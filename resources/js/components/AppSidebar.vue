<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { BadgeCheck, Boxes, Building2, ClipboardCheck, LayoutGrid, PackageSearch, ReceiptText, ShieldCheck, ShoppingBasket, ShoppingCart, Store, Truck, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { useTranslations } from '@/composables/useTranslations';
import { index as backofficeAccess } from '@/routes/backoffice/access';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { index as backofficeBusinesses } from '@/routes/backoffice/businesses';
import { index as backofficeExpenses } from '@/routes/backoffice/expenses';
import { index as backofficeInventory } from '@/routes/backoffice/inventory';
import { index as backofficePreparation } from '@/routes/backoffice/preparation';
import { index as backofficeProducts } from '@/routes/backoffice/products';
import { index as backofficePointsOfSale } from '@/routes/backoffice/points-of-sale';
import { index as backofficePurchases } from '@/routes/backoffice/purchases';
import { index as backofficeSales } from '@/routes/backoffice/sales';
import { index as backofficeStockMovements } from '@/routes/backoffice/stock-movements';
import { index as backofficeTeam } from '@/routes/backoffice/team';
import { dashboard } from '@/routes';
import type { Auth } from '@/types/auth';
import type { NavItem } from '@/types/navigation';

const page = usePage<{ auth: Auth }>();
const { t } = useTranslations();
const currentBusiness = computed(() => page.props.auth.user?.current_business);
const businessCount = computed(
    () => page.props.auth.user?.businesses.length ?? 0,
);
const currentBusinessMembership = computed(() =>
    page.props.auth.user?.businesses.find(
        (business) => business.id === currentBusiness.value?.id,
    ),
);
const backoffice = computed(() => page.props.auth.user?.backoffice);
const membershipRoleLabel = computed(() => {
    if (backoffice.value?.role_label) {
        return backoffice.value.role_label;
    }

    const role = currentBusinessMembership.value?.role;

    if (role === 'owner') {
        return t('owner');
    }

    if (role === 'employee') {
        return t('employee');
    }

    return t('accessActive');
});

const homeHref = computed(() =>
    backoffice.value?.can_access_dashboard ? dashboard() : backofficeBusinesses(),
);

const mainNavItems = computed(() => {
    const items: NavItem[] = [];

    if (backoffice.value?.can_access_dashboard) {
        items.push({
            title: t('dashboard'),
            href: dashboard(),
            icon: LayoutGrid,
        });
    }

    if (backoffice.value?.can_prepare_businesses) {
        items.push(
            {
                title: t('businesses'),
                href: backofficeBusinesses(),
                icon: Building2,
            },
            {
                title: t('products'),
                href: backofficeProducts(),
                icon: Boxes,
            },
            {
                title: t('team'),
                href: backofficeTeam(),
                icon: Users,
            },
            {
                title: t('preparation'),
                href: backofficePreparation(),
                icon: ClipboardCheck,
            },
        );
    }

    if (backoffice.value?.can_view_analytics) {
        items.push(
            {
                title: t('sales'),
                href: backofficeSales(),
                icon: ShoppingCart,
            },
            {
                title: t('purchases'),
                href: backofficePurchases(),
                icon: ShoppingBasket,
            },
            {
                title: t('expenses'),
                href: backofficeExpenses(),
                icon: ReceiptText,
            },
            {
                title: t('pointsOfSale'),
                href: backofficePointsOfSale(),
                icon: Store,
            },
            {
                title: t('inventory'),
                href: backofficeInventory(),
                icon: PackageSearch,
            },
            {
                title: t('stockMovements'),
                href: backofficeStockMovements(),
                icon: Truck,
            },
        );
    }

    if (backoffice.value?.can_manage_users) {
        items.push({
            title: t('access'),
            href: backofficeAccess(),
            icon: ShieldCheck,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="inset"
        class="overflow-x-hidden"
    >
        <SidebarHeader class="gap-3 border-b border-sidebar-border/70 px-3 py-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child class="rounded-2xl px-2 py-2">
                        <Link :href="homeHref" class="flex min-w-0 items-center gap-3">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="px-2 py-3">
            <div
                v-if="currentBusiness"
                class="rounded-2xl border border-sidebar-border/70 bg-linear-to-br from-sidebar-accent/80 via-sidebar to-sidebar px-4 py-4 text-sm shadow-sm group-data-[collapsible=icon]:hidden"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            class="text-[11px] font-semibold uppercase tracking-[0.24em] text-sidebar-foreground/55"
                        >
                            {{ t('activeBusiness') }}
                        </p>
                        <p class="mt-1 truncate text-sm font-semibold text-sidebar-foreground">
                            {{ currentBusiness.name }}
                        </p>
                        <p class="truncate text-xs text-sidebar-foreground/60">
                            {{ currentBusiness.slug }}
                        </p>
                    </div>
                    <span
                        class="inline-flex shrink-0 items-center rounded-full bg-emerald-500/12 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-emerald-700 dark:text-emerald-300"
                    >
                        {{ t('active') }}
                    </span>
                </div>
            </div>

            <SidebarSeparator class="my-3" />

            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border/70 px-2 py-3">
            <div
                class="rounded-2xl border border-sidebar-border/70 bg-sidebar-accent/50 px-4 py-4 shadow-sm group-data-[collapsible=icon]:hidden"
            >
                <div class="flex items-center gap-2 text-sidebar-foreground">
                    <BadgeCheck class="size-4 text-emerald-600 dark:text-emerald-400" />
                    <p class="text-sm font-semibold">{{ t('workspaceReady') }}</p>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2">
                    <div class="rounded-xl bg-sidebar px-3 py-2">
                        <p
                            class="text-[10px] font-semibold uppercase tracking-[0.18em] text-sidebar-foreground/50"
                        >
                            {{ t('businesses') }}
                        </p>
                        <p class="mt-1 text-sm font-semibold text-sidebar-foreground">
                            {{ businessCount }}
                        </p>
                    </div>

                    <div class="rounded-xl bg-sidebar px-3 py-2">
                        <p
                            class="text-[10px] font-semibold uppercase tracking-[0.18em] text-sidebar-foreground/50"
                        >
                            {{ t('currentRole') }}
                        </p>
                        <p class="mt-1 truncate text-sm font-semibold text-sidebar-foreground">
                            {{ membershipRoleLabel }}
                        </p>
                    </div>
                </div>
            </div>

            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
