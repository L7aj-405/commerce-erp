export type TenantOrganization = { id: number; name: string; status: string };
export type TenantStore = { id: number; organization_id: number; name: string; code: string; status: string };
export type SharedPageProps = {
    auth: { user: { id: number; name: string; email: string } | null };
    tenant: { organization: TenantOrganization | null; store: TenantStore | null; organizations: TenantOrganization[]; stores: TenantStore[]; permissions: string[] };
    flash?: { success?: string; invitationUrl?: string };
    [key: string]: unknown;
};
