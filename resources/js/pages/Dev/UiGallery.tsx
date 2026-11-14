import { Alert } from '@/components/ui/Alert';
import { AdvancedFilterBar, ChoiceFilter, DateRangeFilter, FilterSearch } from '@/components/ui/AdvancedFilterBar';
import { Button, ButtonLink, IconButton } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Dialog } from '@/components/ui/Dialog';
import DocBadge from '@/components/ui/DocBadge';
import EmptyState from '@/components/ui/EmptyState';
import { Checkbox, PasswordField, SelectField, TextAreaField, TextField } from '@/components/ui/form';
import { Dropdown, MenuButton, MenuLink } from '@/components/ui/Menu';
import { MetricCard } from '@/components/ui/MetricCard';
import PageHeader from '@/components/ui/PageHeader';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import SearchInput from '@/components/ui/SearchInput';
import { Sheet } from '@/components/ui/Sheet';
import { SkeletonText } from '@/components/ui/Skeleton';
import { InlineLoader } from '@/components/ui/Spinner';
import StatusBadge, { Badge } from '@/components/ui/StatusBadge';
import { TableShell } from '@/components/ui/TableShell';
import ApplicationShell from '@/layouts/ApplicationShell';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';

/*
 * LOCAL-ONLY design-system gallery (route registered only when APP_ENV=local).
 * Everything below is static sample content for visual/keyboard QA — never
 * business data, never linked from navigation.
 */

const sampleOptions = [
    { value: 'casa', label: 'Showroom Casablanca', description: 'Magasin · CASA' },
    { value: 'rabat', label: 'Dépôt Rabat', description: 'Entrepôt · RBT' },
    { value: 'marrakech', label: 'Point de vente Marrakech', description: 'Magasin · MRK' },
    { value: 'tanger', label: 'Entrepôt Tanger', description: 'Entrepôt · TNG', disabled: true },
    { value: 'fes', label: 'Atelier Fès', description: 'Atelier · FES' },
];

const sampleRows = [
    { ref: 'EX-0001', name: 'Exemple — ligne A', qty: 12, amount: '1 650,00 DH', status: 'paid' },
    { ref: 'EX-0002', name: 'Exemple — ligne B', qty: 3, amount: '19 668,00 DH', status: 'partially_paid' },
    { ref: 'EX-0003', name: 'Exemple — ligne C avec un libellé plus long', qty: 0, amount: '0,00 DH', status: 'unpaid' },
    { ref: 'EX-0004', name: 'Exemple — ligne D', qty: 48, amount: '220,00 DH', status: 'draft' },
];

export default function UiGallery() {
    const [store, setStore] = useState<string | null>('casa');
    const [search, setSearch] = useState('');
    const [dialog, setDialog] = useState<'none' | 'form' | 'confirm'>('none');
    const [sheet, setSheet] = useState(false);
    const [alertVisible, setAlertVisible] = useState(true);
    const [tableState, setTableState] = useState<'ready' | 'loading' | 'empty' | 'error' | 'forbidden'>('ready');

    return (
        <ApplicationShell wide>
            <Head title="UI gallery (local)" />
            <PageHeader
                title="Design system"
                description="Galerie locale des composants partagés. Contenu d’exemple uniquement — cette page n’existe qu’en environnement local."
                actions={
                    <>
                        <Button variant="secondary" onClick={() => setSheet(true)}>
                            Ouvrir un panneau
                        </Button>
                        <Button onClick={() => setDialog('form')}>Ouvrir une boîte de dialogue</Button>
                    </>
                }
            />

            <div className="space-y-8">
                <Section id="buttons" title="Buttons & actions">
                    <Card>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button>Primary</Button>
                            <Button variant="secondary">Secondary</Button>
                            <Button variant="outline">Outline</Button>
                            <Button variant="ghost">Ghost</Button>
                            <Button variant="success">Success</Button>
                            <Button variant="danger">Danger</Button>
                            <Button variant="danger-solid">Danger solid</Button>
                            <Button loading loadingText="Enregistrement…">Save</Button>
                            <Button disabled>Disabled</Button>
                            <ButtonLink href="/platform" variant="secondary">
                                Link button
                            </ButtonLink>
                        </div>
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <Button size="sm">Small</Button>
                            <Button size="md">Medium</Button>
                            <Button size="lg">Large</Button>
                            <IconButton label="Paramètres d’exemple" variant="secondary">
                                <GearIcon />
                            </IconButton>
                            <IconButton label="Plus d’options">
                                <DotsIcon />
                            </IconButton>
                            <Dropdown label="Actions" align="start" buttonClassName="inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-field border border-line-strong bg-surface px-3 text-sm font-semibold text-ink hover:bg-sage">
                                <MenuLink href="/platform">Voir le détail</MenuLink>
                                <MenuButton onClick={() => setDialog('confirm')}>Dupliquer</MenuButton>
                                <MenuButton tone="danger" onClick={() => setDialog('confirm')}>
                                    Annuler le document
                                </MenuButton>
                            </Dropdown>
                        </div>
                    </Card>
                </Section>

                <Section id="filters" title="Advanced filters (static demo)">
                    <FilterDemo />
                </Section>

                <Section id="forms" title="Form controls">
                    <Card>
                        <div className="grid gap-5 md:grid-cols-2">
                            <TextField label="Nom du client" required placeholder="Ex. Société Atlas" hint="Tel qu’il apparaîtra sur les documents." />
                            <TextField label="Email" type="email" defaultValue="adresse-invalide" error="Saisissez une adresse email valide." />
                            <SearchableSelect label="Magasin" options={sampleOptions} value={store} onChange={(next) => setStore(next)} required hint="Recherche sans accents : « fes »" />
                            <SelectField label="Mode de paiement" defaultValue="">
                                <option value="">Sélectionner…</option>
                                <option>Espèces</option>
                                <option>Carte</option>
                                <option>Virement</option>
                            </SelectField>
                            <PasswordField label="Mot de passe (exemple)" autoComplete="new-password" />
                            <TextField label="Champ désactivé" disabled defaultValue="Non modifiable" />
                            <TextAreaField label="Note interne" className="md:col-span-2" placeholder="Visible uniquement par l’équipe." />
                            <div className="flex flex-wrap items-center gap-4 md:col-span-2">
                                <Checkbox label="Envoyer une copie par email" defaultChecked />
                                <Checkbox label="Marquer comme prioritaire" />
                            </div>
                            <div className="flex flex-wrap gap-3 md:col-span-2">
                                <SearchInput value={search} onChange={setSearch} searching={search.length > 0} placeholder="Rechercher…" />
                            </div>
                        </div>
                    </Card>
                </Section>

                <Section id="badges" title="Status badges">
                    <Card>
                        <div className="flex flex-wrap gap-2">
                            {['paid', 'partially_paid', 'unpaid', 'draft', 'confirmed', 'cancelled', 'pending', 'overdue', 'archived', 'custom_state'].map((status) => (
                                <StatusBadge key={status} status={status} />
                            ))}
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <DocBadge tone="positive">Émise</DocBadge>
                            <DocBadge tone="warning">Partielle</DocBadge>
                            <DocBadge tone="danger">Annulée</DocBadge>
                            <DocBadge tone="info">Envoyée</DocBadge>
                            <DocBadge>Brouillon</DocBadge>
                            <Badge variant="accent">Sélection</Badge>
                            <Badge variant="neutral" size="sm" dot={false}>
                                Compact
                            </Badge>
                        </div>
                    </Card>
                </Section>

                <Section id="alerts" title="Alerts">
                    <div className="grid gap-3 lg:grid-cols-2">
                        <Alert tone="info" title="Information">
                            Les montants sont affichés TTC.
                        </Alert>
                        <Alert tone="success" title="Enregistré">
                            Le document a été mis à jour.
                        </Alert>
                        <Alert tone="warning" title="Stock bas" actions={<Button size="sm" variant="secondary">Voir le stock</Button>}>
                            3 articles sont sous le seuil d’alerte.
                        </Alert>
                        {alertVisible ? (
                            <Alert tone="danger" title="Échec de synchronisation" onDismiss={() => setAlertVisible(false)}>
                                La dernière synchronisation a échoué. <a href="#alerts">Voir le détail</a>.
                            </Alert>
                        ) : (
                            <Alert tone="neutral">Message masqué.</Alert>
                        )}
                    </div>
                </Section>

                <Section id="metrics" title="Cards & metrics">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <MetricCard label="Neutre" value="19 668,00 DH" helper="Exemple" />
                        <MetricCard label="Entrées" value="12 400,00 DH" tone="positive" helper="Exemple" />
                        <MetricCard label="Sorties" value="3 120,00 DH" tone="negative" helper="Exemple" />
                        <MetricCard label="À surveiller" value="7" tone="warning" helper="Exemple" />
                        <MetricCard label="Prévision" value="—" tone="forecast" loading />
                    </div>
                    <div className="mt-3 grid gap-3 lg:grid-cols-2">
                        <Card title="Carte avec en-tête" description="Titre, description et actions." actions={<Button size="sm" variant="secondary">Action</Button>}>
                            <SkeletonText lines={3} />
                        </Card>
                        <Card title="Chargement" footer={<InlineLoader label="Recalcul…" />}>
                            <p className="text-sm text-ink-muted">Pied de carte avec indicateur de chargement en ligne.</p>
                        </Card>
                    </div>
                </Section>

                <Section id="table" title="Data table">
                    <TableShell
                        label="Tableau d’exemple"
                        state={tableState}
                        onRetry={() => setTableState('ready')}
                        emptyTitle="Aucun document"
                        emptyDescription="Créez un premier document pour le voir ici."
                        emptyAction={<Button size="sm">Créer</Button>}
                        toolbar={
                            <div className="flex flex-wrap gap-2">
                                {(['ready', 'loading', 'empty', 'error', 'forbidden'] as const).map((state) => (
                                    <Button key={state} size="sm" variant={tableState === state ? 'primary' : 'secondary'} onClick={() => setTableState(state)} aria-pressed={tableState === state}>
                                        {state}
                                    </Button>
                                ))}
                            </div>
                        }
                    >
                        <table className="erp-table min-w-[640px] text-sm">
                            <thead>
                                <tr>
                                    <th className="px-4 py-3">Référence</th>
                                    <th className="px-4 py-3">Libellé</th>
                                    <th className="num px-4 py-3">Qté</th>
                                    <th className="num px-4 py-3">Montant</th>
                                    <th className="px-4 py-3">Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sampleRows.map((row) => (
                                    <tr key={row.ref}>
                                        <td className="px-4 py-3 font-medium">{row.ref}</td>
                                        <td className="px-4 py-3">{row.name}</td>
                                        <td className="num px-4 py-3">{row.qty}</td>
                                        <td className="num px-4 py-3 font-semibold">{row.amount}</td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={row.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableShell>
                </Section>

                <Section id="empty" title="Empty state">
                    <EmptyState
                        icon={<DotsIcon />}
                        title="Aucun élément pour le moment"
                        description="Les états vides expliquent ce qui manque et proposent la prochaine action réelle."
                        actions={<Button variant="secondary">Action suivante</Button>}
                    />
                </Section>
            </div>

            <Dialog
                open={dialog === 'form'}
                onClose={() => setDialog('none')}
                title="Boîte de dialogue"
                description="Le focus est piégé et restauré à la fermeture."
                size="md"
                footer={
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDialog('none')}>
                            Annuler
                        </Button>
                        <Button onClick={() => setDialog('none')}>Enregistrer</Button>
                    </div>
                }
            >
                <div className="space-y-4 p-4 sm:p-6">
                    <TextField label="Référence" required data-autofocus />
                    <SearchableSelect label="Magasin" options={sampleOptions} value={store} onChange={(next) => setStore(next)} />
                </div>
            </Dialog>

            <Dialog
                open={dialog === 'confirm'}
                onClose={() => setDialog('none')}
                title="Confirmer l’action"
                description="Action destructive d’exemple."
                size="sm"
                danger
                footer={
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setDialog('none')} data-autofocus>
                            Annuler
                        </Button>
                        <Button variant="danger-solid" onClick={() => setDialog('none')}>
                            Confirmer
                        </Button>
                    </div>
                }
            >
                <p className="p-4 text-sm leading-6 text-ink-muted sm:p-6">Cette action ne peut pas être annulée.</p>
            </Dialog>

            <Sheet open={sheet} onClose={() => setSheet(false)} title="Filtres" description="Panneau latéral partagé." footer={<Button block onClick={() => setSheet(false)}>Appliquer</Button>}>
                <div className="space-y-4 p-4">
                    <SearchableSelect label="Magasin" options={sampleOptions} value={store} onChange={(next) => setStore(next)} />
                    <Checkbox label="Uniquement en stock" />
                </div>
            </Sheet>
        </ApplicationShell>
    );
}

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
    return (
        <section aria-labelledby={`g-${id}`} id={id}>
            <h2 id={`g-${id}`} className="mb-3 text-lg font-semibold tracking-tight text-ink">
                {title}
            </h2>
            {children}
        </section>
    );
}

function GearIcon() {
    return (
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <circle cx="12" cy="12" r="3.5" />
            <path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4" />
        </svg>
    );
}

function DotsIcon() {
    return (
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
            <circle cx="5" cy="12" r="1.6" />
            <circle cx="12" cy="12" r="1.6" />
            <circle cx="19" cy="12" r="1.6" />
        </svg>
    );
}

/** Client-side demo of the shared filter system (no data access). */
function FilterDemo() {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<string | undefined>();
    const [stores, setStores] = useState<string[]>([]);
    const [range, setRange] = useState<{ from?: string; to?: string }>({});
    const statusOptions = [
        { value: 'draft', label: 'Brouillon' },
        { value: 'confirmed', label: 'Confirmée' },
        { value: 'cancelled', label: 'Annulée' },
    ];
    const storeOptions = ['Casablanca', 'Rabat', 'Marrakech', 'Tanger', 'Fès', 'Agadir', 'Meknès', 'Oujda', 'Tétouan'].map((name) => ({ value: name, label: name }));
    const chips = [
        ...(search ? [{ key: 'search', label: 'Recherche', value: search, onRemove: () => setSearch('') }] : []),
        ...(status ? [{ key: 'status', label: 'Statut', value: statusOptions.find((o) => o.value === status)!.label, onRemove: () => setStatus(undefined) }] : []),
        ...stores.map((store) => ({ key: `store-${store}`, label: 'Magasin', value: store, onRemove: () => setStores(stores.filter((s) => s !== store)) })),
        ...(range.from || range.to ? [{ key: 'range', label: 'Période', value: `${range.from ?? '…'} → ${range.to ?? '…'}`, onRemove: () => setRange({}) }] : []),
    ];
    return (
        <AdvancedFilterBar
            search={<FilterSearch value={search} onChange={setSearch} label="Rechercher une commande" placeholder="N°, client, téléphone" />}
            chips={chips}
            activeCount={chips.length}
            onReset={() => {
                setSearch('');
                setStatus(undefined);
                setStores([]);
                setRange({});
            }}
        >
            <ChoiceFilter label="Statut" options={statusOptions} value={status} onChange={setStatus} />
            <ChoiceFilter label="Magasin" multiple options={storeOptions} value={stores} onChange={setStores} />
            <DateRangeFilter label="Période" from={range.from} to={range.to} onChange={setRange} />
        </AdvancedFilterBar>
    );
}
