import { useEffect, useMemo, useState } from 'react';

type Props = {
    name: string;
    imageUrl?: string | null;
    className?: string;
    imageClassName?: string;
    roundedClassName?: string;
    textClassName?: string;
};

export default function ProductImage({ name, imageUrl, className = '', imageClassName = '', roundedClassName = 'rounded-xl', textClassName = 'text-slate-500' }: Props) {
    const [failed, setFailed] = useState(false);
    const initials = useMemo(() => name.trim().slice(0, 2).toUpperCase() || 'PR', [name]);
    const normalizedUrl = useMemo(() => imageUrl?.trim() ?? null, [imageUrl]);

    useEffect(() => {
        setFailed(false);
    }, [normalizedUrl]);

    const src = normalizedUrl && /^https?:\/\//i.test(normalizedUrl) && !failed ? normalizedUrl : null;

    if (!src) {
        return (
            <div className={`flex items-center justify-center bg-slate-100 font-semibold ${roundedClassName} ${textClassName} ${className}`}>
                {initials}
            </div>
        );
    }

    return (
        <img
            src={src}
            alt={name}
            loading="lazy"
            referrerPolicy="no-referrer"
            onError={() => setFailed(true)}
            className={`${roundedClassName} object-contain bg-white ${className} ${imageClassName}`}
        />
    );
}
