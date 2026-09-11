import ApplicationShell from '@/layouts/ApplicationShell';
import type { PropsWithChildren } from 'react';

export default function ProcurementLayout({ children }: PropsWithChildren) {
    return <ApplicationShell>{children}</ApplicationShell>;
}
