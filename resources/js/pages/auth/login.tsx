import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Masuk" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoComplete="email"
                                    placeholder="nama@contoh.invalid"
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

                            <div className="grid grid-cols-[1fr_auto] items-center gap-2">
                                <Label
                                    htmlFor="password"
                                    className="col-start-1 row-start-1"
                                >
                                    Kata sandi
                                </Label>
                                <div className="col-span-2 row-start-2">
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="current-password"
                                        placeholder="Kata sandi"
                                        aria-invalid={Boolean(errors.password)}
                                        aria-describedby={
                                            errors.password
                                                ? 'password-error'
                                                : undefined
                                        }
                                    />
                                </div>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="col-start-2 row-start-1 text-sm"
                                    >
                                        Lupa kata sandi?
                                    </TextLink>
                                )}
                                <InputError
                                    id="password-error"
                                    message={errors.password}
                                    className="col-span-2 row-start-3"
                                />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">Ingat saya</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Masuk
                            </Button>
                        </div>
                        <p className="text-center text-xs leading-5 text-muted-foreground">
                            Akun disediakan oleh administrator. Pendaftaran
                            mandiri tidak tersedia.
                        </p>
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Masuk',
    description: 'Masuk ke SIMRS Campus UEU dengan akun yang diberikan.',
};
