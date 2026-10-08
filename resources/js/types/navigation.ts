import type { Tono } from '@/lib/tonos';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    /** El color de la sección: identifica, no juzga (ver lib/tonos.ts). */
    tono?: Tono;
    isActive?: boolean;
};
