import SalesLayout from '@/layouts/SalesLayout';
import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Note = { id: number; delivery_note_number: string | null; delivery_date: string; recipient_name: string | null; recipient_company: string | null; status: string; store: { name: string }; sales_order: { id: number; order_number: string } };
type PageLink = { url: string | null; label: string; active: boolean };
type Props = { deliveryNotes: { data: Note[]; links: PageLink[] }; filters: Record<string, string | undefined> };

export default function DeliveryNoteIndex({ deliveryNotes, filters }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get('/delivery-notes', Object.fromEntries(new FormData(event.currentTarget).entries()), { preserveState: true, replace: true });
    };
    return <SalesLayout><Head title="Delivery Notes" />
        <div className="mb-5"><h2 className="text-xl font-semibold">Delivery Notes</h2><p className="text-sm text-slate-500">Delivery documents for fulfilled Orders in the active store.</p></div>
        <form onSubmit={submit} className="mb-6 grid gap-3 rounded-lg bg-slate-50 p-4 md:grid-cols-5"><input name="search" defaultValue={filters.search ?? ''} placeholder="Delivery note, order, recipient" className="rounded border px-3 py-2 md:col-span-2" /><input name="delivery_date" type="date" defaultValue={filters.delivery_date ?? ''} className="rounded border px-3 py-2" /><select name="status" defaultValue={filters.status ?? ''} className="rounded border px-3 py-2"><option value="">All statuses</option><option value="draft">Draft</option><option value="issued">Issued</option><option value="cancelled">Cancelled</option></select><button className="rounded bg-slate-900 px-4 py-2 text-white">Filter</button></form>
        <div className="overflow-x-auto rounded-lg border"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr><th className="p-3">Delivery Note #</th><th className="p-3">Delivery date</th><th className="p-3">Order #</th><th className="p-3">Recipient</th><th className="p-3">Store</th><th className="p-3">Status</th></tr></thead><tbody>{deliveryNotes.data.map((note) => <tr key={note.id} className="border-t"><td className="p-3"><Link href={`/delivery-notes/${note.id}`} className="font-medium underline">{note.delivery_note_number ?? `Draft #${note.id}`}</Link></td><td className="p-3">{note.delivery_date.slice(0, 10)}</td><td className="p-3"><Link href={`/sales/orders/${note.sales_order.id}`} className="underline">{note.sales_order.order_number}</Link></td><td className="p-3">{note.recipient_company ?? note.recipient_name ?? 'Walk-in'}</td><td className="p-3">{note.store.name}</td><td className="p-3 capitalize">{note.status}</td></tr>)}{deliveryNotes.data.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-slate-500">No delivery notes match these filters.</td></tr>}</tbody></table></div>
        <nav className="mt-5 flex flex-wrap gap-2">{deliveryNotes.links.map((link) => <Link key={link.label} href={link.url ?? '#'} preserveState className={`rounded border px-3 py-2 text-sm ${link.active ? 'bg-slate-900 text-white' : ''} ${!link.url ? 'pointer-events-none opacity-50' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>
    </SalesLayout>;
}
