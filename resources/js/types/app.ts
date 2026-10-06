export type TenantOrganization = { id: number; name: string; status: string };
export type TenantStore = { id: number; organization_id: number; name: string; code: string; status: string };
export type SharedPageProps = {
    auth: { user: { id: number; name: string; email: string } | null };
    tenant: { organization: TenantOrganization | null; store: TenantStore | null; organizations: TenantOrganization[]; stores: TenantStore[]; permissions: string[] };
    notifications: NotificationSummary;
    flash?: { success?: string; invitationUrl?: string };
    [key: string]: unknown;
};

export type AppNotification = { id: string; category: string; severity: 'info' | 'success' | 'warning' | 'critical'; title: string; message: string; action_url: string | null; metadata: Record<string, unknown>; read_at: string | null; created_at: string };
export type NotificationPreferences = { sound_enabled: boolean; sound_volume: number; disabled_categories: string[] };
export type NotificationSummary = { unread_count: number; recent: AppNotification[]; poll_seconds: number; preferences: NotificationPreferences };
