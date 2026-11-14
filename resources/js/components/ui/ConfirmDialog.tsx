import { useI18n } from '@/i18n';
import { createContext, useCallback, useContext, useRef, useState } from 'react';
import type { PropsWithChildren } from 'react';
import { Button } from './Button';
import { Dialog } from './Dialog';

export type ConfirmOptions = {
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Destructive actions get the danger tone and a solid danger confirm button. */
    danger?: boolean;
};

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

/**
 * Design-system replacement for `window.confirm`: `const confirm = useConfirm();`
 * then `if (!(await confirm({ title, danger: true }))) return;`.
 * Focus starts on Cancel (the safe choice), Escape/backdrop cancel, and focus
 * returns to the control that asked.
 */
export function ConfirmProvider({ children }: PropsWithChildren) {
    const { t } = useI18n();
    const [request, setRequest] = useState<ConfirmOptions | null>(null);
    const resolver = useRef<((value: boolean) => void) | null>(null);

    const confirm = useCallback<ConfirmFn>((options) => {
        resolver.current?.(false);
        setRequest(options);
        return new Promise<boolean>((resolve) => {
            resolver.current = resolve;
        });
    }, []);

    const settle = (value: boolean) => {
        resolver.current?.(value);
        resolver.current = null;
        setRequest(null);
    };

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <Dialog
                open={request !== null}
                onClose={() => settle(false)}
                title={request?.title ?? ''}
                description={request?.description}
                danger={request?.danger}
                size="sm"
                footer={
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" data-autofocus onClick={() => settle(false)}>
                            {request?.cancelLabel ?? t('common.cancel')}
                        </Button>
                        <Button type="button" variant={request?.danger ? 'danger-solid' : 'primary'} onClick={() => settle(true)}>
                            {request?.confirmLabel ?? t('common.confirm')}
                        </Button>
                    </div>
                }
            >
                {null}
            </Dialog>
        </ConfirmContext.Provider>
    );
}

export function useConfirm(): ConfirmFn {
    const confirm = useContext(ConfirmContext);
    if (!confirm) throw new Error('useConfirm must be used inside <ConfirmProvider>.');
    return confirm;
}
