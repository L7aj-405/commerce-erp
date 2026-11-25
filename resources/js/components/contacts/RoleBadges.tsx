import { Badge } from '@/components/ui/StatusBadge';
import { useI18n } from '@/i18n';
import type { Role } from './types';

/** Display name of a role: system roles are translated by their stable key, custom roles show their stored name. */
export function useRoleLabel() {
    const { t } = useI18n();

    return (role: Pick<Role, 'name' | 'system_key'>) => (role.system_key ? t(`cts.role.${role.system_key}` as never) : role.name);
}

const COMMERCIAL: Record<string, 'accent' | 'info'> = { client: 'accent', supplier: 'info' };

export default function RoleBadges({ roles }: { roles: Role[] }) {
    const label = useRoleLabel();
    if (roles.length === 0) return <span className="text-ink-faint">—</span>;

    return (
        <span className="flex flex-wrap gap-1">
            {roles.map((role) => (
                <Badge key={role.id} dot={false} size="sm" variant={(role.system_key && COMMERCIAL[role.system_key]) || 'neutral'}>
                    {label(role)}
                </Badge>
            ))}
        </span>
    );
}
