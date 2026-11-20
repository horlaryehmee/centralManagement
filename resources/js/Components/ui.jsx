import { Link } from '@inertiajs/react';
import { AlertTriangle, Bold, ChevronLeft, Heading2, ImagePlus, Italic, Link2, List, Loader2, MoreHorizontal, Plus, Trash2, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/* ------------------------------------------------------------------ */
/* Layout pieces                                                       */
/* ------------------------------------------------------------------ */

/**
 * Page title. Phones get an app-style large title with a back pill above it,
 * and `fab` ({ href, label, icon }) becomes a floating button over the tab bar
 * (larger screens show it as a normal button next to `actions`).
 */
export function PageHeader({ title, description, actions, back, fab }) {
    const FabIcon = fab?.icon || Plus;
    return (
        <div className="mb-5 flex flex-col gap-3 sm:mb-6 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
            <div className="min-w-0">
                {back && (
                    <Link
                        href={back.href}
                        className="mb-3 -ml-1 inline-flex items-center gap-0.5 rounded-full py-1.5 pr-3.5 pl-1.5 text-sm font-medium text-zinc-700 shadow-xs ring-1 ring-zinc-200 transition active:scale-95 max-sm:bg-white sm:mb-2 sm:ml-0 sm:p-0 sm:text-zinc-500 sm:shadow-none sm:ring-0 sm:hover:text-zinc-900"
                    >
                        <ChevronLeft size={18} className="sm:-ml-1" /> {back.label}
                    </Link>
                )}
                <h1 className="text-[1.75rem] leading-tight font-bold tracking-tight text-balance text-zinc-900 sm:truncate sm:text-2xl">{title}</h1>
                {description && <p className="mt-1 text-sm text-zinc-500">{description}</p>}
            </div>
            {(actions || fab) && (
                <div className="no-scrollbar -mx-4 flex shrink-0 gap-2 overflow-x-auto px-4 empty:hidden sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 [&>*]:shrink-0">
                    {actions}
                    {fab && (
                        <Button href={fab.href} className="hidden sm:inline-flex">
                            <FabIcon size={16} /> {fab.label}
                        </Button>
                    )}
                </div>
            )}
            {fab && (
                <Link
                    href={fab.href}
                    aria-label={fab.label}
                    className="fixed right-4 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-30 flex h-14 items-center gap-2 rounded-2xl bg-zinc-900 pr-5 pl-4 text-sm font-semibold text-white shadow-lg shadow-zinc-900/25 transition active:scale-95 lg:hidden sm:hidden"
                >
                    <FabIcon size={20} /> {fab.label}
                </Link>
            )}
        </div>
    );
}

export function Card({ title, description, children, className = '', actions, padded = true }) {
    return (
        <section className={`rounded-2xl border border-zinc-200/80 bg-white shadow-xs ${className}`}>
            {(title || actions) && (
                <div className="flex items-start justify-between gap-4 border-b border-zinc-100 px-4 py-3.5 sm:px-5 sm:py-4">
                    <div className="min-w-0">
                        {title && <h2 className="text-[15px] font-semibold text-zinc-900 sm:text-base">{title}</h2>}
                        {description && <p className="mt-0.5 text-[13px] leading-snug text-zinc-500 sm:text-sm">{description}</p>}
                    </div>
                    {actions}
                </div>
            )}
            <div className={padded ? 'p-4 sm:p-5' : ''}>{children}</div>
        </section>
    );
}

/**
 * Save/cancel row at the end of a form. Below the desktop breakpoint it sticks
 * to the bottom of the screen (above the tab bar) while its form is in view,
 * with full-width buttons and an "Unsaved" hint once something has changed.
 * Place it as the last child of a padded Card (it bleeds to the card's edges).
 */
export function FormActions({ children, dirty = false, className = '' }) {
    return (
        <div
            className={`z-20 mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-4 max-lg:sticky max-lg:bottom-[calc(4rem+env(safe-area-inset-bottom))] max-lg:-mx-4 max-lg:-mb-4 max-lg:rounded-b-2xl max-lg:bg-white/95 max-lg:px-4 max-lg:pb-4 max-lg:backdrop-blur-md max-lg:[&>a]:flex-1 max-lg:[&>button]:flex-1 sm:max-lg:-mx-5 sm:max-lg:-mb-5 sm:max-lg:px-5 sm:max-lg:pb-5 ${dirty ? 'max-lg:shadow-[0_-12px_24px_-20px_rgba(24,24,27,0.45)]' : ''} ${className}`}
        >
            {dirty && (
                <span className="mr-auto inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-amber-700 lg:hidden">
                    <span className="h-2 w-2 animate-pulse rounded-full bg-amber-500" aria-hidden /> Unsaved
                </span>
            )}
            {children}
        </div>
    );
}

/** Swipeable filter pills (they wrap on larger screens). `options`: [[value, label, count?], …]. */
export function FilterChips({ value, options, onChange, label }) {
    return (
        <div className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0" role="group" aria-label={label}>
            {options.map(([key, text, count]) => {
                const on = value === key;
                return (
                    <button
                        key={key || 'all'}
                        type="button"
                        aria-pressed={on}
                        onClick={() => onChange(key)}
                        className={`flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-sm font-medium transition active:scale-95 ${on ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300'}`}
                    >
                        {text}
                        {count != null && (
                            <span className={`rounded-full px-1.5 text-xs leading-5 ${on ? 'bg-white/20 text-white' : count > 0 && key === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-zinc-100 text-zinc-500'}`}>{count}</span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/**
 * "⋯" button with a list of actions: a bottom sheet on phones, a small menu on
 * larger screens. `items`: [{ label, icon, onClick?, href?, danger? }, …].
 */
export function ActionMenu({ items, label = 'More actions' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return;
        const close = (e) => ref.current && !ref.current.contains(e.target) && !e.target.closest?.('[data-sheet]') && setOpen(false);
        document.addEventListener('pointerdown', close);
        return () => document.removeEventListener('pointerdown', close);
    }, [open]);

    const list = (mobile) =>
        items.filter(Boolean).map((item) => {
            const Icon = item.icon;
            const cls = `flex w-full items-center gap-3 text-left transition ${mobile ? 'rounded-2xl px-4 py-3.5 text-[15px] font-medium active:scale-[0.98] active:bg-zinc-100' : 'rounded-lg px-3 py-2 text-sm hover:bg-zinc-50'} ${item.danger ? 'text-red-600' : 'text-zinc-800'}`;
            const body = (
                <>
                    {Icon && <Icon size={mobile ? 20 : 16} className={item.danger ? '' : 'text-zinc-500'} />}
                    {item.label}
                </>
            );
            return item.href ? (
                <Link key={item.label} href={item.href} className={cls} onClick={() => setOpen(false)}>
                    {body}
                </Link>
            ) : (
                <button
                    key={item.label}
                    type="button"
                    className={cls}
                    onClick={() => {
                        setOpen(false);
                        item.onClick?.();
                    }}
                >
                    {body}
                </button>
            );
        });

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label={label}
                className="flex h-10 w-10 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900 active:scale-90"
            >
                <MoreHorizontal size={20} />
            </button>
            {open && (
                <>
                    <div className="absolute top-full right-0 z-30 mt-1 hidden w-52 rounded-xl border border-zinc-200 bg-white p-1 shadow-xl shadow-zinc-900/10 sm:block" role="menu">
                        {list(false)}
                    </div>
                    <Sheet open onClose={() => setOpen(false)} title={label} className="sm:hidden">
                        <div className="pb-1">{list(true)}</div>
                    </Sheet>
                </>
            )}
        </div>
    );
}

/**
 * Bottom sheet with a drag handle: drag down, tap outside or press Escape to
 * close. Rendered into <body> so sticky or blurred parents can't trap it.
 */
export function Sheet({ open, onClose, title, children, footer, className = '' }) {
    const [drag, setDrag] = useState(0);
    const [dragging, setDragging] = useState(false);
    const start = useRef(null);

    useEffect(() => {
        if (!open) return;
        setDrag(0);
        const esc = (e) => e.key === 'Escape' && onClose();
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', esc);
        return () => {
            document.body.style.overflow = previous;
            window.removeEventListener('keydown', esc);
        };
    }, [open, onClose]);

    if (!open) return null;

    const end = () => {
        if (start.current === null) return;
        start.current = null;
        setDragging(false);
        if (drag > 90) onClose();
        else setDrag(0);
    };

    return createPortal(
        <div data-sheet className={`fixed inset-0 z-50 flex items-end justify-center ${className}`} role="dialog" aria-modal="true" aria-label={title}>
            <div className="absolute inset-0 bg-zinc-950/45 backdrop-blur-[2px] transition-opacity duration-300 starting:opacity-0" onClick={onClose} />
            <div
                className={`relative flex max-h-[88svh] w-full max-w-lg flex-col rounded-t-[1.75rem] bg-white shadow-2xl starting:translate-y-full ${dragging ? '' : 'transition-transform duration-300 ease-[cubic-bezier(.22,.61,.36,1)]'}`}
                style={{ transform: `translateY(${drag}px)` }}
            >
                <div
                    className="flex shrink-0 cursor-grab touch-none justify-center pt-3 pb-1 active:cursor-grabbing"
                    onPointerDown={(e) => {
                        start.current = e.clientY;
                        setDragging(true);
                        e.currentTarget.setPointerCapture(e.pointerId);
                    }}
                    onPointerMove={(e) => start.current !== null && setDrag(Math.max(0, e.clientY - start.current))}
                    onPointerUp={end}
                    onPointerCancel={end}
                >
                    <span className="h-1.5 w-10 rounded-full bg-zinc-300" aria-hidden />
                </div>
                {title && (
                    <div className="flex shrink-0 items-center justify-between px-5 pt-1 pb-2">
                        <h2 className="text-base font-semibold text-zinc-900">{title}</h2>
                        <button type="button" onClick={onClose} className="-mr-2 rounded-full p-2 text-zinc-500 transition hover:bg-zinc-100 active:scale-90" aria-label="Close">
                            <X size={20} />
                        </button>
                    </div>
                )}
                <div className="min-h-0 flex-1 overflow-y-auto px-3">{children}</div>
                {footer ? (
                    <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-zinc-100 px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+0.75rem)] [&>*]:w-full">{footer}</div>
                ) : (
                    <div className="h-[calc(env(safe-area-inset-bottom)+0.75rem)] shrink-0" />
                )}
            </div>
        </div>,
        document.body,
    );
}

/* ------------------------------------------------------------------ */
/* Buttons & badges                                                    */
/* ------------------------------------------------------------------ */

const BUTTON = {
    primary: 'bg-zinc-900 text-white hover:bg-zinc-700',
    secondary: 'border border-zinc-300 bg-white text-zinc-800 hover:bg-zinc-50',
    danger: 'bg-red-600 text-white hover:bg-red-700',
    ghost: 'text-zinc-700 hover:bg-zinc-100',
};

export function Button({ variant = 'primary', loading = false, className = '', children, href, ...props }) {
    const cls = `inline-flex h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold whitespace-nowrap transition-[color,background-color,border-color,transform] duration-150 active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100 sm:h-10 sm:rounded-lg ${BUTTON[variant]} ${className}`;
    if (href) {
        return (
            <Link href={href} className={cls} {...props}>
                {children}
            </Link>
        );
    }
    return (
        <button type="submit" disabled={loading || props.disabled} className={cls} {...props}>
            {loading && <Loader2 size={16} className="animate-spin" />}
            {children}
        </button>
    );
}

const BADGE = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
    gray: 'bg-zinc-100 text-zinc-600 ring-zinc-500/15',
    amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    blue: 'bg-sky-50 text-sky-700 ring-sky-600/15',
    red: 'bg-red-50 text-red-700 ring-red-600/15',
};

export function Badge({ color = 'gray', children }) {
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${BADGE[color]}`}>{children}</span>;
}

export function StatusBadge({ status }) {
    return status === 'published' ? <Badge color="green">Published</Badge> : <Badge color="gray">Draft</Badge>;
}

/* ------------------------------------------------------------------ */
/* Form fields                                                         */
/* ------------------------------------------------------------------ */

export function Field({ label, error, help, children, htmlFor, optional, className = '' }) {
    return (
        <div className={className}>
            {label && (
                <label htmlFor={htmlFor} className="mb-1.5 flex items-baseline justify-between text-sm font-medium text-zinc-800">
                    {label}
                    {optional && <span className="text-xs font-normal text-zinc-400">Optional</span>}
                </label>
            )}
            {children}
            {help && !error && <p className="mt-1.5 text-xs text-zinc-500">{help}</p>}
            {error && <p className="mt-1.5 text-xs font-medium text-red-600">{error}</p>}
        </div>
    );
}

export function TextInput({ label, error, help, optional, className = '', prefix, ...props }) {
    const id = useId();
    return (
        <Field label={label} error={error} help={help} htmlFor={id} optional={optional} className={className}>
            <div className="relative">
                {prefix && <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-zinc-500">{prefix}</span>}
                <input id={id} className={`input ${error ? 'input-error' : ''} ${prefix ? 'pl-9' : ''}`} {...props} value={props.value ?? ''} />
            </div>
        </Field>
    );
}

export function Textarea({ label, error, help, optional, className = '', rows = 4, maxLength, ...props }) {
    const id = useId();
    const length = (props.value ?? '').length;
    return (
        <Field label={label} error={error} help={help} htmlFor={id} optional={optional} className={className}>
            <textarea id={id} rows={rows} maxLength={maxLength} className={`input ${error ? 'input-error' : ''}`} {...props} value={props.value ?? ''} />
            {maxLength && <p className="mt-1 text-right text-xs text-zinc-400">{length} / {maxLength}</p>}
        </Field>
    );
}

export function Select({ label, error, help, options, className = '', placeholder, ...props }) {
    const id = useId();
    return (
        <Field label={label} error={error} help={help} htmlFor={id} className={className}>
            <select id={id} className={`input ${error ? 'input-error' : ''}`} {...props} value={props.value ?? ''}>
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </Field>
    );
}

export function Toggle({ label, description, checked, onChange, error }) {
    const id = useId();
    return (
        <div>
            <label htmlFor={id} className="flex cursor-pointer items-start justify-between gap-4 select-none">
                <span className="min-w-0">
                    <span className="block text-[15px] font-medium text-zinc-800 sm:text-sm">{label}</span>
                    {description && <span className="mt-0.5 block text-[13px] leading-snug text-zinc-500 sm:text-xs">{description}</span>}
                </span>
                <span className="relative mt-0.5 inline-flex shrink-0">
                    <input id={id} type="checkbox" className="peer sr-only" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />
                    <span className="h-7 w-12 rounded-full bg-zinc-300 transition-colors duration-200 peer-checked:bg-emerald-600 peer-focus-visible:ring-2 peer-focus-visible:ring-zinc-900/30 sm:h-6 sm:w-11" />
                    <span className="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform duration-200 ease-[cubic-bezier(.34,1.4,.64,1)] peer-checked:translate-x-5 sm:h-5 sm:w-5" />
                </span>
            </label>
            {error && <p className="mt-1.5 text-xs font-medium text-red-600">{error}</p>}
        </div>
    );
}

export function ColorInput({ label, value, onChange, error, help }) {
    const id = useId();
    return (
        <Field label={label} error={error} help={help} htmlFor={id}>
            <div className="flex items-center gap-2">
                <input type="color" value={value || '#000000'} onChange={(e) => onChange(e.target.value.toUpperCase())} className="h-12 w-14 shrink-0 cursor-pointer rounded-xl border border-zinc-300 bg-white p-1 sm:h-10 sm:w-12 sm:rounded-lg" aria-label={`${label} picker`} />
                <input id={id} type="text" value={value || ''} onChange={(e) => onChange(e.target.value)} maxLength={7} className={`input font-mono uppercase ${error ? 'input-error' : ''}`} placeholder="#000000" />
            </div>
        </Field>
    );
}

/* ------------------------------------------------------------------ */
/* Image picker (single image with preview)                            */
/* ------------------------------------------------------------------ */

export function ImageField({ label, help, error, currentUrl, file, onFile, removed, onRemove, aspect = 'aspect-video', maxMb = 10, removable = true }) {
    const input = useRef(null);
    const [preview, setPreview] = useState(null);

    useEffect(() => {
        if (!file) return setPreview(null);
        const url = URL.createObjectURL(file);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    const shown = preview || (!removed && currentUrl);

    return (
        <Field label={label} help={help ? `${help} Optimised automatically, without visible quality loss.` : `JPEG, PNG or WebP, up to ${maxMb} MB. Optimised automatically, without visible quality loss.`} error={error}>
            <div className={`relative overflow-hidden rounded-xl border-2 border-dashed ${error ? 'border-red-300' : 'border-zinc-200'} bg-zinc-50 ${aspect}`}>
                {shown ? (
                    <>
                        <img src={shown} alt="" className="h-full w-full object-contain" />
                        <div className="absolute top-2 right-2 flex gap-1.5">
                            <button type="button" onClick={() => input.current?.click()} className="rounded-lg bg-white/95 px-3 py-2 text-xs font-semibold text-zinc-800 shadow-sm transition hover:bg-white active:scale-95 sm:px-2.5 sm:py-1.5">
                                Replace
                            </button>
                            {(removable || preview) && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        onFile(null);
                                        if (input.current) input.current.value = '';
                                        if (removable) onRemove?.(true);
                                    }}
                                    className="rounded-lg bg-white/95 p-2 text-red-600 shadow-sm transition hover:bg-white active:scale-95 sm:p-1.5"
                                    aria-label={removable ? 'Remove image' : 'Undo new image'}
                                >
                                    <Trash2 size={15} />
                                </button>
                            )}
                        </div>
                    </>
                ) : (
                    <button type="button" onClick={() => input.current?.click()} className="flex h-full w-full flex-col items-center justify-center gap-2 p-4 text-sm text-zinc-500 hover:bg-zinc-100">
                        <ImagePlus size={26} strokeWidth={1.6} />
                        <span className="font-medium text-zinc-700">Upload image</span>
                    </button>
                )}
            </div>
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(e) => {
                    const f = e.target.files?.[0] || null;
                    onFile(f);
                    if (f) onRemove?.(false);
                }}
            />
        </Field>
    );
}

/* ------------------------------------------------------------------ */
/* Markdown editor (textarea + formatting toolbar)                     */
/* ------------------------------------------------------------------ */

export function MarkdownField({ label, value, onChange, error, help, rows = 10, optional }) {
    const ref = useRef(null);
    const id = useId();

    const wrap = (before, after = before, placeholder = 'text') => {
        const el = ref.current;
        const start = el.selectionStart;
        const end = el.selectionEnd;
        const selected = value?.slice(start, end) || placeholder;
        const next = (value || '').slice(0, start) + before + selected + after + (value || '').slice(end);
        onChange(next);
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(start + before.length, start + before.length + selected.length);
        });
    };

    const linePrefix = (prefix) => {
        const el = ref.current;
        const v = value || '';
        const lineStart = v.lastIndexOf('\n', el.selectionStart - 1) + 1;
        onChange(v.slice(0, lineStart) + prefix + v.slice(lineStart));
        requestAnimationFrame(() => el.focus());
    };

    const tools = [
        { icon: Bold, label: 'Bold', run: () => wrap('**') },
        { icon: Italic, label: 'Italic', run: () => wrap('*') },
        { icon: Heading2, label: 'Heading', run: () => linePrefix('## ') },
        { icon: List, label: 'Bullet list', run: () => linePrefix('- ') },
        { icon: Link2, label: 'Link', run: () => wrap('[', '](https://)', 'link text') },
    ];

    return (
        <Field
            label={label}
            error={error}
            optional={optional}
            htmlFor={id}
            help={help ?? 'Leave a blank line between paragraphs. Use the buttons for bold text, headings, lists and links.'}
        >
            <div className={`overflow-hidden rounded-lg border ${error ? 'border-red-400' : 'border-zinc-300'} focus-within:border-zinc-900 focus-within:ring-2 focus-within:ring-zinc-900/10`}>
                <div className="flex gap-0.5 border-b border-zinc-200 bg-zinc-50 px-1.5 py-1">
                    {tools.map(({ icon: Icon, label: l, run }) => (
                        <button key={l} type="button" onClick={run} title={l} aria-label={l} className="rounded-md p-2 text-zinc-600 hover:bg-white hover:text-zinc-900 active:bg-white sm:p-1.5">
                            <Icon size={16} />
                        </button>
                    ))}
                </div>
                <textarea id={id} ref={ref} rows={rows} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className="block w-full resize-y border-0 px-3 py-2.5 text-base text-zinc-900 focus:ring-0 focus:outline-none sm:text-sm" />
            </div>
        </Field>
    );
}

/* ------------------------------------------------------------------ */
/* Modal / confirmation                                                */
/* ------------------------------------------------------------------ */

/** Dialog: a bottom sheet on phones, a centred card on larger screens. */
export function Modal({ open, onClose, title, children, footer }) {
    useEffect(() => {
        if (!open) return;
        const esc = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [open, onClose]);

    if (!open) return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label={title}>
            <div className="absolute inset-0 bg-zinc-950/45 backdrop-blur-[2px] transition-opacity duration-300 starting:opacity-0" onClick={onClose} />
            <div className="relative w-full max-w-md rounded-t-[1.75rem] bg-white shadow-2xl transition duration-300 ease-[cubic-bezier(.22,.61,.36,1)] starting:translate-y-full sm:rounded-2xl sm:starting:translate-y-4 sm:starting:opacity-0">
                <div className="flex justify-center pt-3 sm:hidden" aria-hidden>
                    <span className="h-1.5 w-10 rounded-full bg-zinc-300" />
                </div>
                <div className="flex items-center justify-between px-5 pt-2 pb-3 sm:border-b sm:border-zinc-100 sm:py-4">
                    <h2 className="text-base font-semibold text-zinc-900">{title}</h2>
                    <button type="button" onClick={onClose} className="-mr-1.5 rounded-full p-2 text-zinc-500 transition hover:bg-zinc-100 active:scale-90 sm:rounded-lg sm:p-1" aria-label="Close">
                        <X size={18} />
                    </button>
                </div>
                <div className="px-5 py-2 text-[15px] text-zinc-600 sm:py-4 sm:text-sm">{children}</div>
                {footer ? (
                    <div className="flex flex-col-reverse gap-2 px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+1rem)] sm:flex-row sm:justify-end sm:border-t sm:border-zinc-100 sm:px-5 sm:py-3 max-sm:[&>*]:w-full">{footer}</div>
                ) : (
                    <div className="h-[calc(env(safe-area-inset-bottom)+1rem)] sm:hidden" />
                )}
            </div>
        </div>,
        document.body,
    );
}

export function ConfirmModal({ open, onClose, onConfirm, title = 'Are you sure?', message, confirmLabel = 'Delete', processing }) {
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            footer={
                <>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="button" variant="danger" onClick={onConfirm} loading={processing}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            <div className="flex gap-3">
                <AlertTriangle size={20} className="mt-0.5 shrink-0 text-red-500" />
                <div>{message}</div>
            </div>
        </Modal>
    );
}

/* ------------------------------------------------------------------ */
/* Pagination & empty state                                            */
/* ------------------------------------------------------------------ */

export function AdminPagination({ paginator }) {
    if (!paginator || paginator.last_page <= 1) return null;
    const prev = paginator.links[0];
    const next = paginator.links[paginator.links.length - 1];
    const pill = 'flex h-11 flex-1 items-center justify-center rounded-xl border text-sm font-semibold transition active:scale-[0.97]';
    return (
        <div className="border-t border-zinc-100 px-4 py-4 sm:px-5">
            {/* Phones: big previous / next buttons with the page number between them. */}
            <div className="flex items-center gap-3 sm:hidden">
                {prev?.url ? (
                    <Link href={prev.url} preserveScroll className={`${pill} border-zinc-200 bg-white text-zinc-800`}>
                        ‹ Previous
                    </Link>
                ) : (
                    <span className={`${pill} border-zinc-100 text-zinc-300`}>‹ Previous</span>
                )}
                <span className="shrink-0 text-xs font-medium text-zinc-500">
                    {paginator.current_page} / {paginator.last_page}
                </span>
                {next?.url ? (
                    <Link href={next.url} preserveScroll className={`${pill} border-zinc-200 bg-white text-zinc-800`}>
                        Next ›
                    </Link>
                ) : (
                    <span className={`${pill} border-zinc-100 text-zinc-300`}>Next ›</span>
                )}
            </div>
            <div className="hidden items-center justify-between gap-3 sm:flex">
                <p className="text-sm text-zinc-500">
                    Showing {paginator.from}–{paginator.to} of {paginator.total}
                </p>
                <div className="flex flex-wrap gap-1">
                    {paginator.links.map((link, i) => {
                        const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›').replace(/<[^>]+>/g, '');
                        return link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                preserveScroll
                                className={`flex h-9 min-w-9 items-center justify-center rounded-lg px-2.5 text-sm ${link.active ? 'bg-zinc-900 font-semibold text-white' : 'text-zinc-700 hover:bg-zinc-100'}`}
                            >
                                {label}
                            </Link>
                        ) : (
                            <span key={i} className="flex h-9 min-w-9 items-center justify-center px-2.5 text-sm text-zinc-300">
                                {label}
                            </span>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export function AdminEmpty({ icon: Icon, title, message, action }) {
    return (
        <div className="flex flex-col items-center px-6 py-14 text-center">
            {Icon && (
                <span className="flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-500">
                    <Icon size={22} />
                </span>
            )}
            <h3 className="mt-4 text-base font-semibold text-zinc-900">{title}</h3>
            {message && <p className="mt-1 max-w-sm text-sm text-zinc-500">{message}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

/** Client-side slug preview (the server makes the final, unique slug). */
export function slugify(text) {
    return (text || '')
        .toString()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 150);
}
