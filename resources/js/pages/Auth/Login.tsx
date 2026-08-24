import { FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';

export default function Login() {
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <>
            <Head title="Sign in" />

            <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6">
                <form onSubmit={submit} className="space-y-5 rounded-xl border border-slate-200 p-8 shadow-sm">
                    <div>
                        <h1 className="text-2xl font-semibold">Commerce ERP</h1>
                        <p className="mt-1 text-sm text-slate-600">Sign in to select your organization and store.</p>
                    </div>

                    <label className="block">
                        <span className="text-sm font-medium">Email</span>
                        <input
                            type="email"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                            required
                            autoFocus
                        />
                        {form.errors.email && <span className="mt-1 block text-sm text-red-600">{form.errors.email}</span>}
                    </label>

                    <label className="block">
                        <span className="text-sm font-medium">Password</span>
                        <input
                            type="password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            className="mt-1 w-full rounded border border-slate-300 px-3 py-2"
                            required
                        />
                    </label>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(event) => form.setData('remember', event.target.checked)}
                        />
                        Remember me
                    </label>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
                    >
                        Sign in
                    </button>
                </form>
            </main>
        </>
    );
}
