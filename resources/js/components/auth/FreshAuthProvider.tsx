import { Button } from '@/components/ui/Button';
import { PasswordField, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import axios, { AxiosError, CanceledError, type AxiosRequestConfig, type AxiosResponse } from 'axios';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type FormEvent,
    type PropsWithChildren,
} from 'react';

type FreshAuthChallenge = {
    code: 'FRESH_AUTH_REQUIRED';
    level: 'L1' | 'L2';
    level_value: number;
    requires_password: boolean;
    requires_mfa: boolean;
    has_password: boolean;
    google_connected: boolean;
    timeout_minutes: number;
    reauthentication_url: string;
};

type VerificationResponse = Partial<FreshAuthChallenge> & {
    confirmed: boolean;
    requires_mfa: boolean;
};

type PendingRequest = {
    execute: () => Promise<unknown>;
    resolve: (response: unknown) => void;
    reject: (reason: unknown) => void;
    cancel: () => void;
};

type FreshAuthContextValue = {
    active: boolean;
    cancel: () => void;
};

const FreshAuthContext = createContext<FreshAuthContextValue | null>(null);

function isFreshAuthChallenge(value: unknown): value is FreshAuthChallenge {
    if (!value || typeof value !== 'object') return false;
    const candidate = value as Partial<FreshAuthChallenge>;
    return candidate.code === 'FRESH_AUTH_REQUIRED'
        && (candidate.level === 'L1' || candidate.level === 'L2')
        && typeof candidate.requires_password === 'boolean'
        && typeof candidate.requires_mfa === 'boolean';
}

function verificationError(error: unknown, field: 'password' | 'code', fallback: string, throttled: string): string {
    if (!axios.isAxiosError(error)) return fallback;
    if (error.response?.status === 429) return throttled;
    const errors = error.response?.data?.errors as Record<string, string[] | undefined> | undefined;
    return errors?.[field]?.[0] ?? fallback;
}

function FreshAuthModal({
    challenge,
    retrying,
    onCancel,
    onVerified,
    onChallengeChange,
}: {
    challenge: FreshAuthChallenge;
    retrying: boolean;
    onCancel: () => void;
    onVerified: () => void;
    onChallengeChange: (challenge: FreshAuthChallenge) => void;
}) {
    const { direction, t } = useI18n();
    const dialogRef = useRef<HTMLDivElement>(null);
    const googlePollRef = useRef<number | null>(null);
    const [password, setPassword] = useState('');
    const [code, setCode] = useState('');
    const [error, setError] = useState('');
    const [verifying, setVerifying] = useState(false);
    const [googlePending, setGooglePending] = useState(false);
    const needsPassword = challenge.requires_password;

    useEffect(() => {
        const dialog = dialogRef.current;
        if (!dialog) return;
        const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const focusable = () => Array.from(dialog.querySelectorAll<HTMLElement>(
            'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
        ));
        focusable()[0]?.focus();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !verifying && !retrying && !googlePending) {
                event.preventDefault();
                onCancel();
                return;
            }
            if (event.key !== 'Tab') return;
            const elements = focusable();
            if (elements.length === 0) return;
            const first = elements[0];
            const last = elements[elements.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            previous?.focus();
        };
    }, [googlePending, onCancel, retrying, verifying]);

    useEffect(() => () => {
        if (googlePollRef.current !== null) window.clearInterval(googlePollRef.current);
    }, []);

    const submitPassword = async (event: FormEvent) => {
        event.preventDefault();
        setError('');
        setVerifying(true);
        try {
            const response = await axios.post<VerificationResponse>('/security/confirm/password', { password }, {
                headers: { Accept: 'application/json', 'X-Fresh-Auth-Verification': '1' },
            });
            setPassword('');
            if (response.data.confirmed) onVerified();
            else onChallengeChange({ ...challenge, requires_password: false, requires_mfa: true });
        } catch (requestError) {
            setError(verificationError(requestError, 'password', t('freshAuth.incorrectPassword'), t('freshAuth.tooManyAttempts')));
        } finally {
            setVerifying(false);
        }
    };

    const submitMfa = async (event: FormEvent) => {
        event.preventDefault();
        setError('');
        setVerifying(true);
        try {
            const response = await axios.post<VerificationResponse>('/security/confirm/two-factor', { code }, {
                headers: { Accept: 'application/json', 'X-Fresh-Auth-Verification': '1' },
            });
            setCode('');
            if (response.data.confirmed) onVerified();
        } catch (requestError) {
            setError(verificationError(requestError, 'code', t('freshAuth.incorrectMfa'), t('freshAuth.tooManyAttempts')));
        } finally {
            setVerifying(false);
        }
    };

    const confirmWithGoogle = () => {
        const popup = window.open('/security/confirm/google', 'fresh-auth-google', 'popup,width=520,height=720');
        if (!popup) {
            setError(t('freshAuth.popupBlocked'));
            return;
        }

        setError('');
        setGooglePending(true);
        googlePollRef.current = window.setInterval(async () => {
            if (popup.closed) {
                if (googlePollRef.current !== null) window.clearInterval(googlePollRef.current);
                googlePollRef.current = null;
                setGooglePending(false);
                return;
            }
            try {
                const response = await axios.get<VerificationResponse>('/security/confirm/status', {
                    headers: { Accept: 'application/json', 'X-Fresh-Auth-Verification': '1' },
                });
                if (response.data.confirmed) {
                    popup.close();
                    if (googlePollRef.current !== null) window.clearInterval(googlePollRef.current);
                    googlePollRef.current = null;
                    setGooglePending(false);
                    onVerified();
                } else if (response.data.requires_mfa) {
                    popup.close();
                    if (googlePollRef.current !== null) window.clearInterval(googlePollRef.current);
                    googlePollRef.current = null;
                    setGooglePending(false);
                    onChallengeChange({ ...challenge, requires_password: false, requires_mfa: true });
                }
            } catch {
                // The popup may still be crossing Google; polling remains safe and same-origin.
            }
        }, 700);
    };

    const busy = verifying || retrying || googlePending;

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-ink/45 p-4" dir={direction}>
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="fresh-auth-title"
                aria-describedby="fresh-auth-description"
                className="w-full max-w-md rounded-panel border border-line bg-surface p-5 shadow-pop sm:p-6"
            >
                <div className="mb-5">
                    <div className="mb-3 flex size-10 items-center justify-center rounded-full bg-sage text-primary" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
                            <rect x="5" y="10" width="14" height="10" rx="2" />
                            <path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2" />
                        </svg>
                    </div>
                    <h2 id="fresh-auth-title" className="text-lg font-semibold text-ink">{t('freshAuth.title')}</h2>
                    <p id="fresh-auth-description" className="mt-1 text-sm leading-6 text-ink-muted">
                        {t('freshAuth.description')} {challenge.timeout_minutes} {t('freshAuth.minutes')}.
                    </p>
                </div>

                {error && <p role="alert" className="mb-4 rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{error}</p>}

                {needsPassword ? (
                    <>
                        {challenge.has_password && (
                            <form onSubmit={submitPassword} className="space-y-4" noValidate>
                                <PasswordField
                                    label={t('freshAuth.password')}
                                    name="fresh-auth-password"
                                    autoComplete="current-password"
                                    required
                                    value={password}
                                    onChange={(event) => setPassword(event.target.value)}
                                />
                                <Button type="submit" block disabled={busy || password.length === 0}>
                                    {verifying ? t('freshAuth.verifying') : t('freshAuth.continue')}
                                </Button>
                            </form>
                        )}
                        {challenge.google_connected && (
                            <div className={challenge.has_password ? 'mt-4 border-t border-line pt-4' : ''}>
                                <Button type="button" variant="secondary" block disabled={busy} onClick={confirmWithGoogle}>
                                    {googlePending ? t('freshAuth.verifying') : t('freshAuth.confirmGoogle')}
                                </Button>
                            </div>
                        )}
                        {!challenge.has_password && !challenge.google_connected && (
                            <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm text-danger">{t('freshAuth.noMethod')}</p>
                        )}
                    </>
                ) : (
                    <form onSubmit={submitMfa} className="space-y-4" noValidate>
                        <TextField
                            label={t('freshAuth.mfaOrRecovery')}
                            name="fresh-auth-code"
                            autoComplete="one-time-code"
                            inputMode="numeric"
                            required
                            value={code}
                            onChange={(event) => setCode(event.target.value)}
                        />
                        <p className="text-xs leading-5 text-ink-muted">{t('freshAuth.recoveryHint')}</p>
                        <Button type="submit" block disabled={busy || code.trim().length === 0}>
                            {verifying ? t('freshAuth.verifying') : t('freshAuth.confirm')}
                        </Button>
                    </form>
                )}

                <Button type="button" variant="ghost" block className="mt-3" disabled={busy} onClick={onCancel}>
                    {retrying ? t('freshAuth.resuming') : t('freshAuth.cancel')}
                </Button>
            </div>
        </div>
    );
}

export function FreshAuthProvider({ children }: PropsWithChildren) {
    const [challenge, setChallenge] = useState<FreshAuthChallenge | null>(null);
    const [retrying, setRetrying] = useState(false);
    const pendingRef = useRef<PendingRequest | null>(null);

    useEffect(() => {
        const interceptor = axios.interceptors.response.use(undefined, (error: AxiosError) => {
            if (error.response?.status !== 409 || !isFreshAuthChallenge(error.response.data) || !error.config) {
                return Promise.reject(error);
            }
            if (pendingRef.current || error.config.headers?.['X-Fresh-Auth-Verification']) {
                return Promise.reject(error);
            }

            return new Promise<AxiosResponse>((resolve, reject) => {
                const config = error.config as AxiosRequestConfig;
                pendingRef.current = {
                    execute: () => axios.request({
                        ...config,
                        headers: { ...config.headers, 'X-Fresh-Auth-Retry': '1' },
                        cancelToken: undefined,
                        signal: undefined,
                    }),
                    resolve: (response) => resolve(response as AxiosResponse),
                    reject,
                    cancel: () => reject(new CanceledError('Fresh authentication cancelled')),
                };
                setChallenge(error.response?.data as FreshAuthChallenge);
            });
        });

        const nativeFetch = window.fetch.bind(window);
        window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
            const response = await nativeFetch(input, init);
            if (response.status !== 409 || pendingRef.current) return response;

            let data: unknown;
            try {
                data = await response.clone().json();
            } catch {
                return response;
            }
            if (!isFreshAuthChallenge(data)) return response;

            return new Promise<Response>((resolve, reject) => {
                pendingRef.current = {
                    execute: () => nativeFetch(input, init),
                    resolve: (retriedResponse) => resolve(retriedResponse as Response),
                    reject,
                    cancel: () => resolve(response),
                };
                setChallenge(data);
            });
        };

        return () => {
            axios.interceptors.response.eject(interceptor);
            window.fetch = nativeFetch;
        };
    }, []);

    const cancel = useCallback(() => {
        const pending = pendingRef.current;
        pendingRef.current = null;
        setChallenge(null);
        setRetrying(false);
        pending?.cancel();
    }, []);

    const retryPending = useCallback(async () => {
        const pending = pendingRef.current;
        if (!pending) return;
        setRetrying(true);
        try {
            const response = await pending.execute();
            pending.resolve(response);
        } catch (error) {
            pending.reject(error);
        } finally {
            pendingRef.current = null;
            setRetrying(false);
            setChallenge(null);
        }
    }, []);

    return (
        <FreshAuthContext.Provider value={{ active: challenge !== null, cancel }}>
            {children}
            {challenge && (
                <FreshAuthModal
                    challenge={challenge}
                    retrying={retrying}
                    onCancel={cancel}
                    onVerified={retryPending}
                    onChallengeChange={setChallenge}
                />
            )}
        </FreshAuthContext.Provider>
    );
}

export function useFreshAuth(): FreshAuthContextValue {
    const context = useContext(FreshAuthContext);
    if (!context) throw new Error('useFreshAuth must be used inside FreshAuthProvider.');
    return context;
}
