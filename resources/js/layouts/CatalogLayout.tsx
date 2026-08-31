import ApplicationShell from '@/layouts/ApplicationShell';
import type { PropsWithChildren } from 'react';
export default function CatalogLayout({ children }: PropsWithChildren) {
    return <ApplicationShell>{children}</ApplicationShell>;
}
