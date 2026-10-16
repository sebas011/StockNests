import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function PortableSetup() {
    return (
        <>
            <Head title="Set up StockNest" />

            <div className="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                <ShieldCheck
                    aria-hidden="true"
                    className="mt-0.5 size-5 shrink-0"
                />
                <p>
                    Create the administrator for this PC. Your GAA and APPCSE
                    workspaces are ready, and you can add your team after
                    signing in.
                </p>
            </div>

            <Form
                action="/setup"
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Your name</Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                autoFocus
                                autoComplete="name"
                                maxLength={255}
                                aria-invalid={Boolean(errors.name)}
                                aria-describedby={
                                    errors.name ? 'name-error' : undefined
                                }
                            />
                            <InputError id="name-error" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoComplete="email"
                                maxLength={255}
                                placeholder="you@example.com"
                                aria-invalid={Boolean(errors.email)}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                minLength={12}
                                autoComplete="new-password"
                                aria-invalid={Boolean(errors.password)}
                                aria-describedby="password-help password-error"
                            />
                            <p
                                id="password-help"
                                className="text-xs leading-relaxed text-muted-foreground"
                            >
                                Use at least 12 characters, including uppercase
                                and lowercase letters, a number, and a symbol.
                                Keep this password somewhere safe.
                            </p>
                            <InputError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                required
                                minLength={12}
                                autoComplete="new-password"
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-1 w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {processing
                                ? 'Creating your account…'
                                : 'Create administrator'}
                        </Button>

                        <p className="text-center text-xs leading-relaxed text-muted-foreground">
                            This setup closes automatically after your account
                            is created. Your records stay in the data folder on
                            this PC.
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

PortableSetup.layout = {
    title: 'Welcome to StockNest',
    description: 'One quick step to start managing your inventory and budget.',
};
