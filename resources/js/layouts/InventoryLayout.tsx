import ApplicationShell from '@/layouts/ApplicationShell';
import type { WorkspaceWidth } from '@/layouts/ApplicationShell';
import type { PropsWithChildren } from 'react';

/** Full-width data workspace by default; pass width="reading" for single-column forms. */
export default function InventoryLayout({ children, width = 'full' }: PropsWithChildren<{ width?: WorkspaceWidth; wide?: boolean }>) {
    return <ApplicationShell width={width}>{children}</ApplicationShell>;
}
