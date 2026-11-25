import { Checkbox, TextField } from '@/components/ui/form';
import { useI18n } from '@/i18n';
import type { ContactCan, ContactValues, Role } from './types';

type Props = {
    /** The persisted catalogue (system roles + the organization custom roles). Never a second list in React. */
    roles: Role[];
    values: Pick<ContactValues, 'role_keys' | 'role_ids' | 'other' | 'custom_role'>;
    can: ContactCan;
    error?: string;
    onChange: (patch: Partial<Pick<ContactValues, 'role_keys' | 'role_ids' | 'other' | 'custom_role'>>) => void;
    /** Quick commercial contexts restrict the choice to their own roles. */
    allowedKeys?: string[];
    /** Hide the "Autre" mechanism (quick forms). */
    allowOther?: boolean;
};

/**
 * Multiple simultaneous roles. "Autre" is only an interaction mechanism: it reveals an input whose value is persisted by the
 * server as a reusable organization role (or matched to an existing one). It never grants access or financial privileges.
 * A role the user may not assign is hidden, unless it is already part of the contact.
 */
export default function RoleSelector({ roles, values, can, error, onChange, allowedKeys, allowOther = true }: Props) {
    const { t } = useI18n();

    const allowed = (role: Role): boolean => {
        if (allowedKeys && !(role.system_key !== null && allowedKeys.includes(role.system_key))) return false;
        const selected = role.system_key ? values.role_keys.includes(role.system_key) : values.role_ids.includes(role.id);
        if (selected) return true;
        if (role.system_key === 'client') return can.createCustomer;
        if (role.system_key === 'supplier') return can.createSupplier;

        return true;
    };

    const isOn = (role: Role) => (role.system_key ? values.role_keys.includes(role.system_key) : values.role_ids.includes(role.id));
    const toggle = (role: Role, on: boolean) => {
        if (role.system_key) {
            const key = role.system_key;
            onChange({ role_keys: on ? [...values.role_keys, key] : values.role_keys.filter((k) => k !== key) });
        } else {
            onChange({ role_ids: on ? [...values.role_ids, role.id] : values.role_ids.filter((id) => id !== role.id) });
        }
    };
    const visible = roles.filter(allowed);

    return (
        <fieldset className="min-w-0">
            <legend className="mb-1.5 text-sm font-medium text-ink">{t('cts.roles')}</legend>
            <p className="mb-2 text-[13px] text-ink-muted">{t('cts.rolesHelp')}</p>
            <div className="grid gap-x-4 gap-y-0.5 sm:grid-cols-2">
                {visible.map((role) => (
                    <Checkbox
                        key={role.id}
                        checked={isOn(role)}
                        onChange={(event) => toggle(role, event.target.checked)}
                        label={role.is_system && role.system_key ? t(`cts.role.${role.system_key}` as never) : role.name}
                    />
                ))}
                {allowOther && can.manageRoles && (
                    <Checkbox checked={values.other} onChange={(event) => onChange({ other: event.target.checked })} label={t('cts.roleOther')} />
                )}
            </div>
            {allowOther && can.manageRoles && values.other && (
                <TextField
                    className="mt-3 max-w-sm"
                    label={t('cts.newRole')}
                    hint={t('cts.newRoleHelp')}
                    value={values.custom_role}
                    maxLength={80}
                    onChange={(event) => onChange({ custom_role: event.target.value })}
                    error={error}
                    data-autofocus
                />
            )}
        </fieldset>
    );
}
