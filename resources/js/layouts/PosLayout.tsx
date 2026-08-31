import ApplicationShell from '@/layouts/ApplicationShell';
import type { PropsWithChildren } from 'react';

export default function PosLayout({ children }: PropsWithChildren) {
    return <ApplicationShell wide>{children}</ApplicationShell>;
}
