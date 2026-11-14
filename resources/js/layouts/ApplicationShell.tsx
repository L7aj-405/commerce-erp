import { BrandLockup, PRODUCT_NAME } from '@/components/ui/brand';
import NotificationBell from '@/components/notifications/NotificationBell';
import SandboxBanner from '@/components/SandboxBanner';
import CommandPalette from '@/components/shell/CommandPalette';
import { useToast } from '@/components/ui/toast';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { LocaleSwitcher, useI18n } from '@/i18n';
import type { SharedPageProps } from '@/types/app';
import { Link, router, usePage } from '@inertiajs/react';
import { Dropdown, MenuLink } from '@/components/ui/Menu';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { MutableRefObject, PropsWithChildren, ReactNode } from 'react';
import {
    buildNavGroups,
    buildQuickActions,
    DisclosureChevron,
    firstNavItem,
    hasPermission as tenantHasPermission,
    IconChevronDown,
    IconClose,
    IconContact,
    IconCart,
    IconMenu,
    IconMoon,
    IconMore,
    IconPackage,
    IconPlus,
    IconRegister,
    IconSearch,
    IconStore,
    IconSun,
    isNavSection,
    matchActiveNav,
    visibleNavGroups,
} from './navigation';
import type { NavEntry, NavGroup, NavItem } from './navigation';

// Hover-intent timings: a short dwell at the edge opens; leaving the drawer corridor
// closes only after a grace period (diagonal / overshooting movements survive).
const HOVER_OPEN_DELAY = 180;
const HOVER_CLOSE_DELAY = 380;
/** Width of the screen-edge activation zone (px). The floating rail starts at 24px. */
const EDGE_ZONE = 18;
/** Extra px beyond the drawer's inline-end edge that still count as "inside". */
const DRAWER_CORRIDOR = 32;
/** Header row height: the edge zone starts below it so header controls are never armed. */
const EDGE_TOP_OFFSET = 64;

export type WorkspaceWidth = 'full' | 'reading';

/**
 * Workspace width system.
 * - `full` (default): data workspaces (index, detail, dashboards, reports) use all
 *   the width beside the rail, with responsive gutters.
 * - `reading`: settings, feeds and long single-column forms keep a 1280px measure.
 * Legacy `wide` / `fluid` props are accepted and mean `full`.
 */
export default function ApplicationShell({
    children,
    width = 'full',
    flush = false,
}: PropsWithChildren<{ width?: WorkspaceWidth; wide?: boolean; fluid?: boolean; flush?: boolean }>) {
    const widthClass = width === 'reading' ? 'max-w-7xl' : 'max-w-none';
    const [paletteOpen, setPaletteOpen] = useState(false);

    // Header centering: equal side tracks sized to the wider of brand/controls, applied
    // only from lg up and only while the centred track keeps a usable width (else the
    // tabs take the remaining space and collapse into More — never overlap).
    const headerRowRef = useRef<HTMLDivElement | null>(null);
    const headerStartRef = useRef<HTMLDivElement | null>(null);
    const headerEndRef = useRef<HTMLDivElement | null>(null);
    const [sideWidth, setSideWidth] = useState<number | null>(null);
    useLayoutEffect(() => {
        const row = headerRowRef.current;
        if (!row) return;
        const measure = () => {
            const start = headerStartRef.current?.offsetWidth ?? 0;
            const end = headerEndRef.current?.offsetWidth ?? 0;
            if (window.innerWidth < 1024) return setSideWidth(null);
            const styles = getComputedStyle(row);
            const inner = row.clientWidth - parseFloat(styles.paddingLeft) - parseFloat(styles.paddingRight);
            const side = Math.ceil(Math.max(start, end));
            const gap = parseFloat(styles.columnGap) || 12;
            setSideWidth(inner - side * 2 - gap * 2 >= 320 ? side : null);
        };
        measure();
        const observer = new ResizeObserver(measure);
        [row, headerStartRef.current, headerEndRef.current].forEach((element) => element && observer.observe(element));
        return () => observer.disconnect();
    }, []);
    const page = usePage<SharedPageProps & { sandbox?: { active: boolean } }>();
    const { auth, tenant, flash } = page.props;
    // `notifications` is a shared summary prop, but /notifications passes its own paginated
    // list under the same key (Inertia page props win). Only hand the bell a real summary;
    // otherwise it starts from the empty default and fills itself from /notifications/feed.
    const sharedNotifications = page.props.notifications as unknown;
    const notifications =
        sharedNotifications && Array.isArray((sharedNotifications as { recent?: unknown }).recent)
            ? (sharedNotifications as typeof page.props.notifications)
            : undefined;
    const { direction, t } = useI18n();
    const currentPath = page.url.split('?')[0];

    // Bridge server flash("success", …) redirects into the toast system so every
    // module's "Enregistré" / "Lancé" confirmation is delivered consistently.
    const toast = useToast();
    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
    }, [flash?.success, toast]);

    const can = (permission?: string | string[]) => tenantHasPermission(tenant, permission);
    const groups = useMemo(() => visibleNavGroups(buildNavGroups(t, tenant), tenant), [t, tenant]);
    const activeMatch = matchActiveNav(groups, currentPath);
    const activeGroup = groups.find((group) => group.key === activeMatch?.groupKey);

    /* ---------------- Navigation drawer ---------------- */
    // `hover` = opened from the edge strip (closes when the pointer leaves);
    // `pinned` = opened explicitly by click/keyboard (closes via Esc, backdrop or close button).
    const [drawer, setDrawer] = useState<'closed' | 'hover' | 'pinned'>('closed');
    const launcherRef = useRef<HTMLButtonElement | null>(null);
    const hoverTimer = useRef<number | undefined>(undefined);
    const canHover = useMediaQuery('(hover: hover) and (pointer: fine) and (min-width: 1024px)');

    const clearHoverTimer = () => window.clearTimeout(hoverTimer.current);
    const openPinned = (opener?: HTMLButtonElement | null) => {
        clearHoverTimer();
        if (opener) launcherRef.current = opener;
        setDrawer('pinned');
    };
    const drawerPanelRef = useRef<HTMLDivElement | null>(null);
    const closeDrawer = useCallback(() => {
        window.clearTimeout(hoverTimer.current);
        // A pointer still resting on the edge must leave it before hover may reopen.
        if (pointerInEdge.current) edgeArmed.current = false;
        setDrawer((state) => {
            if (state === 'pinned') window.setTimeout(() => launcherRef.current?.focus(), 0);
            return 'closed';
        });
    }, []);

    useEffect(() => {
        setDrawer('closed');
        setPaletteOpen(false);
    }, [currentPath]);

    useEffect(() => () => window.clearTimeout(hoverTimer.current), []);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setDrawer('closed');
                setPaletteOpen((value) => !value);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /*
     * Hover preview (fine pointers, lg+ only). One passive document listener decides from
     * the pointer position instead of enter/leave events on several overlapping layers:
     *  - closed: dwelling in the edge zone (below the header) opens after a delay;
     *  - hover:  anywhere inside the drawer or its corridor cancels a pending close,
     *            outside it schedules one;
     *  - after any close the edge must be left once before hover can reopen (no flicker
     *    loop when the pointer is parked on the edge);
     *  - pinned: hover logic is ignored entirely (manual state wins).
     */
    const drawerRef = useRef(drawer);
    drawerRef.current = drawer;
    const edgeArmed = useRef(true);
    const pointerInEdge = useRef(false);
    const pendingAction = useRef<'open' | 'close' | null>(null);
    useEffect(() => {
        if (!canHover) return;
        const schedule = (action: 'open' | 'close', delay: number, run: () => void) => {
            if (pendingAction.current === action) return;
            window.clearTimeout(hoverTimer.current);
            pendingAction.current = action;
            hoverTimer.current = window.setTimeout(() => {
                pendingAction.current = null;
                run();
            }, delay);
        };
        const cancel = () => {
            window.clearTimeout(hoverTimer.current);
            pendingAction.current = null;
        };
        const onMove = (event: PointerEvent) => {
            if (event.pointerType !== 'mouse') return;
            const rtl = document.documentElement.dir === 'rtl';
            const fromStart = rtl ? window.innerWidth - event.clientX : event.clientX;
            const inEdge = fromStart <= EDGE_ZONE && event.clientY >= EDGE_TOP_OFFSET;
            const state = drawerRef.current;
            pointerInEdge.current = inEdge;
            if (!inEdge) edgeArmed.current = true;

            if (state === 'pinned') return cancel();
            if (state === 'closed') {
                if (!inEdge) {
                    if (pendingAction.current === 'open') cancel();
                    return;
                }
                if (edgeArmed.current && !document.querySelector('[aria-modal="true"]')) {
                    schedule('open', HOVER_OPEN_DELAY, () => setDrawer((current) => (current === 'closed' ? 'hover' : current)));
                }
                return;
            }
            // state === 'hover'
            const panel = drawerPanelRef.current?.getBoundingClientRect();
            const reach = panel ? (rtl ? window.innerWidth - panel.left : panel.right) + DRAWER_CORRIDOR : 0;
            if (fromStart <= reach || inEdge) {
                if (pendingAction.current === 'close') cancel();
                return;
            }
            schedule('close', HOVER_CLOSE_DELAY, () => setDrawer((current) => (current === 'hover' ? 'closed' : current)));
        };
        // Pointer leaving the window: close a hover preview after the grace period.
        const onLeave = (event: MouseEvent) => {
            if (event.relatedTarget === null && drawerRef.current === 'hover') {
                schedule('close', HOVER_CLOSE_DELAY, () => setDrawer((current) => (current === 'hover' ? 'closed' : current)));
            }
        };
        document.addEventListener('pointermove', onMove, { passive: true });
        document.documentElement.addEventListener('mouseleave', onLeave);
        return () => {
            cancel();
            document.removeEventListener('pointermove', onMove);
            document.documentElement.removeEventListener('mouseleave', onLeave);
        };
    }, [canHover]);

    const toggleFromMenuButton = (button: HTMLButtonElement) => {
        if (drawer === 'pinned') closeDrawer();
        else openPinned(button);
    };

    return (
        <div className={`app-canvas text-ink ${flush ? 'h-dvh overflow-hidden' : 'min-h-dvh'}`}>
            <a
                href="#erp-main"
                className="sr-only z-[60] rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-fg focus:not-sr-only focus:fixed focus:top-3 focus:start-3"
            >
                {t('shell.skipToContent')}
            </a>
            {/* No invisible edge strip: the hover zone is evaluated from pointer coordinates
                (see the hover-intent effect), so nothing blocks clicks near the edge. */}

            {/* Discoverable reveal handle on the true screen edge, centred at 50vh.
                Hover (pointer devices) previews the drawer; click / tap / Enter pins it open. */}
            {/* Stays mounted while the drawer is open (hidden, inert) so focus can return to it on close. */}
            {(
                <button
                    type="button"
                    onClick={(event) => openPinned(event.currentTarget)}
                    aria-label={t('shell.openNavigation')}
                    aria-controls="erp-nav-drawer"
                    aria-expanded={drawer !== 'closed'}
                    aria-hidden={drawer === 'pinned' || undefined}
                    tabIndex={drawer === 'pinned' ? -1 : undefined}
                    className={`edge-handle fixed start-0 top-1/2 z-[41] flex h-[55px] w-[11px] cursor-pointer items-center justify-center rounded-e-[10px] bg-[linear-gradient(#b9ddd5,#248a7d,#b9ddd5)] text-white opacity-60 shadow-[0_2px_8px_rgb(0_93_86/0.25)] transition-[width,opacity] hover:w-4 hover:opacity-100 focus-visible:w-4 focus-visible:opacity-100 dark:bg-[linear-gradient(#1e4a45,#3fb8ab,#1e4a45)] ${
                        drawer === 'pinned' ? 'pointer-events-none opacity-0' : ''
                    }`}
                >
                    <svg aria-hidden="true" className="directional-icon rtl:rotate-180" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="m9 6 6 6-6 6" />
                    </svg>
                </button>
            )}

            <IconRail groups={groups} activeGroupKey={activeMatch?.groupKey} />
            <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} groups={groups} />

            <NavDrawer
                open={drawer !== 'closed'}
                mode={drawer}
                groups={groups}
                activeHref={activeMatch?.item.href}
                activeGroupKey={activeMatch?.groupKey}
                activeSectionKey={activeMatch?.sectionKey ?? null}
                direction={direction}
                onClose={closeDrawer}
                panelRef={drawerPanelRef}
                onPin={() => {
                    clearHoverTimer();
                    setDrawer('pinned');
                }}
                contextSwitcher={<ContextSelects />}
            />

            <div className={`lg:ps-[5.625rem] ${flush ? 'flex h-dvh flex-col' : ''}`}>
                <SandboxBanner />
                {/* Three-region header: brand (start) · contextual navigation (centered) · user controls (end).
                    It spans the full viewport above the rail column; the side groups are measured and
                    given equal tracks so the navigation is centered on the header, not on leftover space. */}
                <header
                    className={`z-30 px-2 pt-2 sm:px-3.5 lg:-ms-[5.625rem] ${flush ? 'shrink-0' : page.props.sandbox?.active ? '' : 'sticky top-0'}`}
                >
                    <div ref={headerRowRef} className="glass-nav flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[19px] px-2.5 py-1.5 sm:px-4 lg:h-12 lg:flex-nowrap lg:py-0 lg:ps-6 lg:pe-3">
                        <div className="flex shrink-0 items-center" style={sideWidth ? { width: sideWidth } : undefined}>
                            <div ref={headerStartRef} className="flex w-max items-center gap-2">
                                <button
                                    type="button"
                                    onClick={(event) => toggleFromMenuButton(event.currentTarget)}
                                    aria-label={drawer === 'pinned' ? t('common.closeMenu') : t('common.openMenu')}
                                    aria-expanded={drawer === 'pinned'}
                                    aria-controls="erp-nav-drawer"
                                    className={`flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full transition-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:size-10 ${
                                        drawer === 'pinned' ? 'bg-accent-soft text-accent ring-1 ring-accent/30' : 'glass-pill text-ink hover:text-accent'
                                    }`}
                                >
                                    <IconMenu />
                                </button>
                                <Link href="/platform" aria-label={`${PRODUCT_NAME} — ${t('nav.dashboard')}`} className="rounded-lg focus-visible:outline-offset-4">
                                    <BrandLockup size={34} className="[&>span:last-child]:hidden sm:[&>span:last-child]:block" />
                                </Link>
                            </div>
                        </div>

                        {/* Contextual navigation: its own row below lg, centered track from lg up. */}
                        <div className="order-last flex w-full min-w-0 justify-center lg:order-none lg:w-auto lg:flex-1">
                            {activeGroup && activeGroup.items.length > 1 ? (
                                <ModuleTabs key={activeGroup.key} group={activeGroup} activeHref={activeMatch?.item.href} activeSectionKey={activeMatch?.sectionKey ?? null} />
                            ) : activeGroup ? (
                                <p className="hidden items-center gap-2 text-sm font-semibold text-ink lg:flex">
                                    <span aria-hidden="true" className="text-accent [&>svg]:size-[18px]">
                                        {activeGroup.icon}
                                    </span>
                                    {activeGroup.label}
                                </p>
                            ) : null}
                        </div>

                        <div className="ms-auto flex shrink-0 items-center justify-end lg:ms-0" style={sideWidth ? { width: sideWidth } : undefined}>
                            <div ref={headerEndRef} className="flex w-max items-center gap-1 sm:gap-2">
                                <SearchTrigger onOpen={() => setPaletteOpen(true)} />
                                <ContextMenu />
                                <QuickCreateMenu />
                                <ThemeToggle />
                                <NotificationBell
                                    initial={
                                        notifications ?? {
                                            unread_count: 0,
                                            recent: [],
                                            poll_seconds: 20,
                                            preferences: { sound_enabled: false, sound_volume: 0.5, disabled_categories: [] },
                                        }
                                    }
                                />
                                <ProfileMenu
                                    userName={auth.user?.name}
                                    userEmail={auth.user?.email}
                                    showSandbox={can('sandbox.access')}
                                    showUsers={!!tenant.organization && can('members.view')}
                                    showSettings={can(['settings.view', 'settings.update'])}
                                    organizationId={tenant.organization?.id}
                                />
                            </div>
                        </div>
                    </div>
                </header>

                {flush ? (
                    <main id="erp-main" tabIndex={-1} className="min-h-0 flex-1 overflow-hidden focus:outline-none">{children}</main>
                ) : (
                    <main id="erp-main" tabIndex={-1} className={`mx-auto min-w-0 px-4 py-5 focus:outline-none sm:px-6 lg:px-7 lg:pt-[18px] lg:pb-12 min-[2000px]:pe-9 ${widthClass}`}>{children}</main>
                )}
            </div>
        </div>
    );
}

/* ====================================================================== */
/* Icon rail                                                              */
/* ====================================================================== */

function IconRail({ groups, activeGroupKey }: { groups: NavGroup[]; activeGroupKey?: string }) {
    const { t } = useI18n();
    // Floating pill: height follows its content (never full height), centred on the viewport.
    // Opening the full drawer is done from the screen-edge handle / header search, never by hovering icons.
    return (
        <aside
            aria-label={t('shell.mainNavigation')}
            className="glass-nav fixed start-6 top-1/2 z-30 hidden h-max max-h-[calc(100dvh-8rem)] w-[57px] -translate-y-1/2 flex-col items-center rounded-[25px] py-2.5 lg:flex"
        >
            <nav className="flex min-h-0 w-full flex-col items-center gap-2 overflow-y-auto px-[9px] [scrollbar-width:none]">
                {groups.map((group) => {
                    const active = group.key === activeGroupKey;
                    return (
                        <Link
                            key={group.key}
                            href={firstNavItem(group).href}
                            aria-label={group.label}
                            aria-current={active ? 'page' : undefined}
                            className={`group relative flex h-[39px] w-[38px] shrink-0 items-center justify-center rounded-[13px] transition-soft [&>svg]:size-[18px] ${
                                active
                                    ? 'btn-gradient text-primary-fg shadow-[0_6px_12px_rgb(0_117_103/0.2)]'
                                    : 'text-ink-muted hover:bg-accent-soft hover:text-accent'
                            }`}
                        >
                            {group.icon}
                            <RailTooltip label={group.label} />
                        </Link>
                    );
                })}
            </nav>
        </aside>
    );
}

function RailTooltip({ label }: { label: string }) {
    // Visual only — the parent already carries the accessible name.
    return (
        <span
            aria-hidden="true"
            className="pointer-events-none fixed z-50 whitespace-nowrap rounded-lg bg-ink px-2.5 py-1.5 text-[13px] font-semibold text-surface opacity-0 shadow-pop transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100"
            style={{ insetInlineStart: '5.25rem' }}
        >
            {label}
        </span>
    );
}

/* ====================================================================== */
/* Full navigation drawer                                                 */
/* ====================================================================== */

function NavDrawer({
    open,
    mode,
    groups,
    activeHref,
    activeGroupKey,
    activeSectionKey,
    direction,
    onClose,
    panelRef: externalPanelRef,
    onPin,
    contextSwitcher,
}: {
    open: boolean;
    mode: 'closed' | 'hover' | 'pinned';
    groups: NavGroup[];
    activeHref?: string;
    activeGroupKey?: string;
    activeSectionKey: string | null;
    direction: 'ltr' | 'rtl';
    onClose: () => void;
    panelRef: MutableRefObject<HTMLDivElement | null>;
    onPin: () => void;
    contextSwitcher: ReactNode;
}) {
    const { t } = useI18n();
    const panelRef = useRef<HTMLDivElement | null>(null);
    const searchRef = useRef<HTMLInputElement | null>(null);
    const [query, setQuery] = useState('');
    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});
    const [openSections, setOpenSections] = useState<Record<string, boolean>>({});

    useEffect(() => {
        if (!open) {
            setQuery('');
            return;
        }
        setOpenGroups((current) => (activeGroupKey ? { ...current, [activeGroupKey]: true } : current));
        if (activeSectionKey) setOpenSections((current) => ({ ...current, [activeSectionKey]: true }));
        if (mode === 'pinned') searchRef.current?.focus();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode === 'pinned']);

    // Esc to close, Tab kept inside the drawer, page scroll locked while pinned.
    useEffect(() => {
        if (!open) return;
        const previousOverflow = document.documentElement.style.overflow;
        if (mode === 'pinned') document.documentElement.style.overflow = 'hidden';
        const handler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
                return;
            }
            if (event.key !== 'Tab' || !panelRef.current) return;
            const focusable = panelRef.current.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input, select:not([disabled])');
            if (focusable.length === 0) return;
            const first = focusable[0]!;
            const last = focusable[focusable.length - 1]!;
            if (!panelRef.current.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        window.addEventListener('keydown', handler);
        return () => {
            document.documentElement.style.overflow = previousOverflow;
            window.removeEventListener('keydown', handler);
        };
    }, [open, mode, onClose]);

    const normalized = query.trim().toLocaleLowerCase();
    const searchResults = useMemo(() => {
        if (!normalized) return null;
        return groups
            .map((group) => {
                const items: NavItem[] = [];
                group.items.forEach((entry) => {
                    const candidates = isNavSection(entry) ? entry.items : [entry];
                    candidates.forEach((item) => {
                        const haystack = `${group.label} ${isNavSection(entry) ? entry.label : ''} ${item.label}`.toLocaleLowerCase();
                        if (haystack.includes(normalized)) items.push(item);
                    });
                });
                return { group, items };
            })
            .filter((result) => result.items.length > 0);
    }, [groups, normalized]);

    const itemLink = (item: NavItem) => {
        const active = activeHref === item.href;
        return (
            <Link
                key={item.href}
                href={item.href}
                aria-current={active ? 'page' : undefined}
                className={`group relative flex min-h-10 items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-soft ${
                    active ? 'bg-accent-soft font-semibold text-accent' : 'text-ink-muted hover:bg-sage hover:text-ink'
                }`}
            >
                <span aria-hidden="true" className={active ? 'text-accent' : 'text-ink-faint group-hover:text-ink-muted'}>
                    {item.icon}
                </span>
                <span className="min-w-0 truncate">{item.label}</span>
            </Link>
        );
    };

    return (
        <>
            {/* Scrim only when pinned; a hover preview never covers the page with an invisible layer. */}
            {open && mode === 'pinned' && (
                <button
                    type="button"
                    tabIndex={-1}
                    aria-label={t('common.closeMenu')}
                    onClick={onClose}
                    className="animate-overlay-in glass-scrim fixed inset-0 z-40 cursor-default"
                />
            )}
            <div
                id="erp-nav-drawer"
                ref={(node) => {
                    panelRef.current = node;
                    externalPanelRef.current = node;
                }}
                role="dialog"
                aria-modal={mode === 'pinned' ? true : undefined}
                aria-label={t('shell.mainNavigation')}
                hidden={!open}
                onFocus={() => mode === 'hover' && onPin()}
                onPointerDown={() => mode === 'hover' && onPin()}
                className="animate-drawer-in fixed inset-y-0 start-0 z-50 flex w-[88vw] max-w-[22rem] flex-col glass-popover border-y-0 border-s-0 lg:inset-y-3 lg:start-3 lg:rounded-panel lg:border"
            >
                <div className="flex h-16 shrink-0 items-center gap-2.5 border-b border-line px-3">
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label={t('common.closeMenu')}
                        aria-expanded={true}
                        aria-controls="erp-nav-drawer"
                        className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent-soft text-accent ring-1 ring-accent/30 transition-soft hover:bg-accent/15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    >
                        <IconClose />
                    </button>
                    <Link href="/platform" className="rounded-lg">
                        <BrandLockup />
                    </Link>
                </div>

                <div className="shrink-0 space-y-3 border-b border-line p-3">
                    <label className="relative block">
                        <span className="sr-only">{t('shell.searchNavigation')}</span>
                        <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-faint">
                            <IconSearch />
                        </span>
                        <input
                            ref={searchRef}
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('shell.searchNavigation')}
                            className="control-input h-11 rounded-xl bg-raised ps-9"
                        />
                    </label>
                    <div className="sm:hidden">{contextSwitcher}</div>
                </div>

                <nav className="min-h-0 flex-1 overflow-y-auto px-3 py-3" aria-label={t('shell.mainNavigation')}>
                    {searchResults ? (
                        searchResults.length === 0 ? (
                            <p className="px-3 py-6 text-center text-sm text-ink-muted">{t('shell.noNavResults')}</p>
                        ) : (
                            <div className="space-y-4">
                                {searchResults.map(({ group, items }) => (
                                    <div key={group.key}>
                                        <p className="px-3 pb-1 text-[13px] font-medium text-ink-faint">{group.label}</p>
                                        <div className="space-y-0.5">{items.map((item) => itemLink(item))}</div>
                                    </div>
                                ))}
                            </div>
                        )
                    ) : (
                        <ul className="space-y-1">
                            {groups.map((group) => {
                                const expanded = openGroups[group.key] ?? false;
                                const groupActive = activeGroupKey === group.key;
                                return (
                                    <li key={group.key}>
                                        <button
                                            type="button"
                                            onClick={() => setOpenGroups((current) => ({ ...current, [group.key]: !expanded }))}
                                            aria-expanded={expanded}
                                            aria-controls={`drawer-group-${group.key}`}
                                            className={`group flex min-h-11 w-full cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-start text-[15px] transition-soft ${
                                                groupActive ? 'font-semibold text-ink' : 'font-medium text-ink-muted hover:bg-sage hover:text-ink'
                                            }`}
                                        >
                                            <span aria-hidden="true" className={`[&>svg]:size-[18px] ${groupActive ? 'text-accent' : 'text-ink-faint group-hover:text-ink-muted'}`}>
                                                {group.icon}
                                            </span>
                                            <span className="min-w-0 flex-1 truncate">{group.label}</span>
                                            <DisclosureChevron open={expanded} direction={direction} />
                                        </button>
                                        {expanded && (
                                            <div id={`drawer-group-${group.key}`} className="mt-0.5 mb-1 ms-[1.3rem] space-y-0.5 border-s border-line ps-2">
                                                {group.items.map((entry) => {
                                                    if (!isNavSection(entry)) return itemLink(entry);
                                                    const sectionOpen = openSections[entry.key] ?? false;
                                                    return (
                                                        <div key={entry.key}>
                                                            <button
                                                                type="button"
                                                                onClick={() => setOpenSections((current) => ({ ...current, [entry.key]: !sectionOpen }))}
                                                                aria-expanded={sectionOpen}
                                                                aria-controls={`drawer-section-${entry.key}`}
                                                                className={`group flex min-h-10 w-full cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-start text-sm transition-soft ${
                                                                    activeSectionKey === entry.key ? 'font-semibold text-ink' : 'text-ink-muted hover:bg-sage hover:text-ink'
                                                                }`}
                                                            >
                                                                <span aria-hidden="true" className="text-ink-faint">
                                                                    {entry.icon}
                                                                </span>
                                                                <span className="min-w-0 flex-1 truncate">{entry.label}</span>
                                                                <DisclosureChevron open={sectionOpen} direction={direction} />
                                                            </button>
                                                            {sectionOpen && (
                                                                <div id={`drawer-section-${entry.key}`} className="ms-4 space-y-0.5 border-s border-line ps-2">
                                                                    {entry.items.map((item) => itemLink(item))}
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </nav>
            </div>
        </>
    );
}

/* ====================================================================== */
/* Contextual header tabs with grouped dropdowns + responsive overflow     */
/* ====================================================================== */

function ModuleTabs({ group, activeHref, activeSectionKey }: { group: NavGroup; activeHref?: string; activeSectionKey: string | null }) {
    const { t } = useI18n();
    const containerRef = useRef<HTMLDivElement | null>(null);
    const measureRef = useRef<HTMLDivElement | null>(null);
    const [visibleCount, setVisibleCount] = useState(group.items.length);

    const recompute = useCallback(() => {
        const container = containerRef.current;
        const measure = measureRef.current;
        if (!container || !measure) return;
        const widths = Array.from(measure.children).map((child) => (child as HTMLElement).offsetWidth + 4);
        const moreWidth = widths.pop() ?? 96; // last measured child is the More button
        const available = container.clientWidth;
        const total = widths.reduce((sum, width) => sum + width, 0);
        if (total <= available) {
            setVisibleCount(widths.length);
            return;
        }
        let used = moreWidth;
        let count = 0;
        for (const width of widths) {
            if (used + width > available) break;
            used += width;
            count += 1;
        }
        setVisibleCount(count);
    }, []);

    useLayoutEffect(() => {
        recompute();
        const observer = new ResizeObserver(recompute);
        if (containerRef.current) observer.observe(containerRef.current);
        void document.fonts?.ready.then(recompute);
        return () => observer.disconnect();
    }, [recompute, group]);

    const visible = group.items.slice(0, visibleCount);
    const overflow = group.items.slice(visibleCount);
    const overflowActive = overflow.some((entry) => entryContains(entry, activeHref));

    return (
        <nav aria-label={t('shell.moduleNavigation')} ref={containerRef} className="relative w-full min-w-0">
            {/* Measuring row mirrors the real tab widths. The zero-size clipping wrapper keeps
                it from widening the page (it caused horizontal scroll before). */}
            <div aria-hidden="true" className="pointer-events-none invisible absolute top-0 start-0 size-0 overflow-hidden">
                <div ref={measureRef} className="flex w-max gap-1 whitespace-nowrap [&>*]:shrink-0">
                    {group.items.map((entry) => (
                        <span key={entryKey(entry)} className={tabClass(false)}>
                            {entry.label}
                            {isNavSection(entry) && <IconChevronDown />}
                        </span>
                    ))}
                    <span className={tabClass(false)}>
                        <IconMore />
                        {t('shell.more')}
                        <IconChevronDown />
                    </span>
                </div>
            </div>

            <ul className="flex items-center gap-0.5 lg:justify-center">
                {visible.map((entry) => (
                    <li key={entryKey(entry)} className="shrink-0">
                        {isNavSection(entry) ? (
                            <Dropdown
                                label={entry.label}
                                active={activeSectionKey === entry.key}
                                buttonClassName={tabClass(activeSectionKey === entry.key)}
                            >
                                {entry.items.map((item) => (
                                    <MenuLink key={item.href} href={item.href} active={activeHref === item.href} icon={item.icon}>
                                        {item.label}
                                    </MenuLink>
                                ))}
                            </Dropdown>
                        ) : (
                            <Link href={entry.href} aria-current={activeHref === entry.href ? 'page' : undefined} className={tabClass(activeHref === entry.href)}>
                                {entry.label}
                            </Link>
                        )}
                    </li>
                ))}
                {overflow.length > 0 && (
                    <li className="shrink-0">
                        <Dropdown
                            label={t('shell.more')}
                            icon={<IconMore />}
                            active={overflowActive}
                            buttonClassName={tabClass(overflowActive)}
                            align="end"
                        >
                            {overflow.map((entry) =>
                                isNavSection(entry) ? (
                                    <div key={entry.key} role="group" aria-label={entry.label} className="py-1">
                                        <p className="px-3 pt-1 pb-1 text-[13px] font-medium text-ink-faint">{entry.label}</p>
                                        {entry.items.map((item) => (
                                            <MenuLink key={item.href} href={item.href} active={activeHref === item.href} icon={item.icon}>
                                                {item.label}
                                            </MenuLink>
                                        ))}
                                    </div>
                                ) : (
                                    <MenuLink key={entry.href} href={entry.href} active={activeHref === entry.href} icon={entry.icon}>
                                        {entry.label}
                                    </MenuLink>
                                ),
                            )}
                        </Dropdown>
                    </li>
                )}
            </ul>
        </nav>
    );
}

const entryKey = (entry: NavEntry) => (isNavSection(entry) ? entry.key : entry.href);
const entryContains = (entry: NavEntry, href?: string) => (isNavSection(entry) ? entry.items.some((item) => item.href === href) : entry.href === href);

function tabClass(active: boolean) {
    // Editorial tabs: quiet text, a petrol underline marks the current page/section.
    return `relative inline-flex min-h-10 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-[15px] tracking-[-0.01em] xl:text-base transition-soft after:pointer-events-none after:absolute after:inset-x-3 after:bottom-0 after:h-[3px] after:rounded-full after:transition-soft lg:min-h-12 lg:rounded-none lg:px-3.5 ${
        active
            ? 'font-bold text-ink after:bg-primary'
            : 'font-medium text-ink-muted after:bg-transparent hover:text-ink hover:after:bg-line-strong data-[active]:text-ink'
    }`;
}

/* ====================================================================== */
/* Header controls                                                        */
/* ====================================================================== */

const iconButton =
    'glass-pill relative flex size-9 cursor-pointer items-center justify-center rounded-full text-ink-muted transition-soft hover:text-ink sm:size-10 [&>svg]:size-[18px]';

function ContextSelects() {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    return (
        <div className="grid gap-3">
            <label className="block">
                <span className="mb-1 block text-xs font-semibold text-ink-muted">{t('common.organization')}</span>
                <select
                    value={tenant.organization?.id ?? ''}
                    onChange={(event) => event.target.value && router.post(`/context/organizations/${event.target.value}`)}
                    className="control-select"
                >
                    <option value="">{t('common.organization')}</option>
                    {tenant.organizations.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="block">
                <span className="mb-1 block text-xs font-semibold text-ink-muted">{t('shell.storeContext')}</span>
                <select
                    value={tenant.store?.id ?? ''}
                    onChange={(event) => event.target.value && router.post(`/context/stores/${event.target.value}`)}
                    disabled={!tenant.organization}
                    className="control-select"
                >
                    <option value="">{t('shell.storeContext')}</option>
                    {tenant.stores.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name} · {item.code}
                        </option>
                    ))}
                </select>
            </label>
        </div>
    );
}

/** Header search pill → opens the centered global command palette (also Ctrl/⌘+K). */
function SearchTrigger({ onOpen }: { onOpen: () => void }) {
    const { t } = useI18n();
    const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
    return (
        <button
            type="button"
            onClick={onOpen}
            aria-label={t('palette.title')}
            title={`${t('palette.title')} (${mac ? '⌘' : 'Ctrl'} K)`}
            aria-haspopup="dialog"
            aria-keyshortcuts="Control+K Meta+K"
            className="glass-pill flex size-9 shrink-0 cursor-pointer items-center justify-center gap-2.5 rounded-full text-sm text-ink-muted transition-soft hover:text-ink sm:size-10 xl:w-auto xl:min-w-[11.5rem] xl:ps-3 xl:pe-1.5 [&>svg]:size-[18px]"
        >
            <IconSearch />
            <span className="hidden flex-1 text-start text-[13px] xl:inline">{t('palette.trigger')}</span>
            <kbd className="hidden rounded-md border border-line bg-raised px-1.5 py-0.5 font-sans text-[11px] font-medium text-ink-muted xl:inline">{mac ? '⌘ K' : 'Ctrl K'}</kbd>
        </button>
    );
}

/** Organization/store context — compact trigger showing the active store, selects inside. */
function ContextMenu() {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const summary = tenant.store?.name ?? tenant.organization?.name ?? t('shell.storeContext');
    return (
        <div className="hidden sm:block">
            <Dropdown
                label={t('shell.context')}
                icon={
                    <span className="flex min-w-0 items-center gap-2">
                        <IconStore />
                        <span className="hidden max-w-40 truncate text-[13px] font-semibold text-ink xl:inline">{summary}</span>
                    </span>
                }
                hideLabel
                align="end"
                width="w-72"
                buttonClassName="glass-pill flex min-h-10 min-w-10 cursor-pointer items-center justify-center gap-2 rounded-full px-2.5 text-ink-muted transition-soft hover:text-ink xl:px-3 [&_svg]:size-[17px]"
            >
                <div className="p-2">
                    <p className="mb-3 text-sm font-semibold text-ink">{t('shell.context')}</p>
                    <ContextSelects />
                </div>
            </Dropdown>
        </div>
    );
}

/** Quick create — only real, permitted workflows. */
function QuickCreateMenu() {
    const { t } = useI18n();
    const { tenant } = usePage<SharedPageProps>().props;
    const actions = buildQuickActions(t, tenant);

    if (actions.length === 0) return null;

    return (
        <Dropdown
            label={t('shell.create')}
            icon={<IconPlus />}
            align="end"
            width="w-60"
            buttonClassName="btn-gradient flex min-h-9 cursor-pointer items-center gap-1 rounded-full px-2.5 text-sm font-semibold text-primary-fg transition-soft hover:bg-primary-hover sm:min-h-10 md:gap-1.5 md:px-4 [&_span]:hidden md:[&_span]:inline"
        >
            {actions.map((action) => (
                <MenuLink key={action.href} href={action.href} icon={action.icon}>
                    {action.label}
                </MenuLink>
            ))}
        </Dropdown>
    );
}

function ThemeToggle() {
    const { t } = useI18n();
    const [dark, setDark] = useState(false);
    useEffect(() => {
        setDark(document.documentElement.dataset.theme === 'dark');
    }, []);
    const toggle = () => {
        const next = !dark;
        setDark(next);
        if (next) document.documentElement.dataset.theme = 'dark';
        else delete document.documentElement.dataset.theme;
        try {
            window.localStorage.setItem('erp.theme', next ? 'dark' : 'light');
        } catch {
            /* storage unavailable */
        }
    };
    const label = dark ? t('shell.themeLight') : t('shell.themeDark');
    return (
        <button type="button" onClick={toggle} aria-label={label} title={label} aria-pressed={dark} className={iconButton}>
            {dark ? <IconSun /> : <IconMoon />}
        </button>
    );
}

function ProfileMenu({
    userName,
    userEmail,
    showSandbox,
    showUsers,
    showSettings,
    organizationId,
}: {
    userName?: string;
    userEmail?: string;
    showSandbox: boolean;
    showUsers: boolean;
    showSettings: boolean;
    organizationId?: number | string;
}) {
    const { t } = useI18n();
    const initial = (userName ?? '?').trim().charAt(0).toUpperCase();
    return (
        <Dropdown
            label={userName ?? t('common.profile')}
            icon={
                <span aria-hidden="true" className="grid size-8 place-items-center rounded-full bg-ink text-[13px] font-semibold text-surface">
                    {initial}
                </span>
            }
            hideLabel
            align="end"
            width="w-64"
            buttonClassName="glass-pill flex cursor-pointer items-center rounded-full p-1 transition-soft hover:ring-2 hover:ring-accent/30"
        >
            <div className="px-3 py-2">
                <p className="text-sm font-semibold text-ink">{userName}</p>
                <p className="truncate text-[13px] text-ink-muted">{userEmail}</p>
            </div>
            <MenuLink href="/account/profile">{t('common.profile')}</MenuLink>
            <MenuLink href="/security">{t('common.security')}</MenuLink>
            <MenuLink href="/account/notifications">{t('common.notifications')}</MenuLink>
            {(showUsers || showSettings || showSandbox) && <div className="my-1 border-t border-line" />}
            {showUsers && organizationId !== undefined && <MenuLink href={`/organizations/${organizationId}/users-access`}>{t('common.usersAccess')}</MenuLink>}
            {showSettings && <MenuLink href="/document-profile">{t('common.organizationSettings')}</MenuLink>}
            {showSandbox && <MenuLink href="/test-mode">Mode test</MenuLink>}
            <div className="my-1 border-t border-line" />
            <LocaleSwitcher />
            <div className="my-1 border-t border-line" />
            <button
                type="button"
                onClick={() => router.post('/logout')}
                className="block min-h-10 w-full cursor-pointer rounded-lg px-3 py-2 text-start text-sm font-medium text-danger transition-soft hover:bg-danger-soft"
            >
                {t('common.logout')}
            </button>
        </Dropdown>
    );
}
