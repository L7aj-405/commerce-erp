import { toWhatsAppDigits } from '@/utils/phone';

export type WhatsAppDestination = {
    available: boolean;
    type: 'username' | 'phone' | null;
    destination: string | null;
    base_url: string | null;
    link: string | null;
};

export function whatsappBaseUrl(destination: WhatsAppDestination, manualPhone?: string): string | null {
    if (destination.type === 'username' && destination.base_url) return destination.base_url;

    const digits = toWhatsAppDigits(manualPhone ?? destination.destination);
    return digits ? `https://wa.me/${digits}` : null;
}

export function whatsappLink(destination: WhatsAppDestination, message = '', manualPhone?: string): string | null {
    const baseUrl = whatsappBaseUrl(destination, manualPhone);
    if (!baseUrl) return null;

    return message ? `${baseUrl}?text=${encodeURIComponent(message)}` : baseUrl;
}
