import ApplicationShell from '@/layouts/ApplicationShell';
import type { PropsWithChildren } from 'react';

export default function InventoryLayout({ children, wide = false }: PropsWithChildren<{ wide?: boolean }>) {
    return <ApplicationShell wide={wide}>{children}</ApplicationShell>;
}
