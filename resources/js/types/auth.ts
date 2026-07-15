export type User = {
    publicId: string;
    name: string;
    email: string;
    emailVerifiedAt: string | null;
    status: 'ACTIVE' | 'SUSPENDED' | 'INACTIVE';
    isSystemAdministrator: boolean;
    twoFactorEnabled: boolean;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
