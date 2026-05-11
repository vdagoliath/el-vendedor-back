import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const messages = {
    en: {
        backoffice: 'Backoffice',
        operations: 'Operations',
        dashboard: 'Dashboard',
        businesses: 'Businesses',
        team: 'Team',
        sales: 'Sales',
        purchases: 'Purchases',
        expenses: 'Expenses',
        pointsOfSale: 'Points of Sale',
        products: 'Products',
        preparation: 'Preparation',
        access: 'Access',
        activeBusiness: 'Active Business',
        active: 'Active',
        workspaceReady: 'Workspace Ready',
        currentRole: 'Current Role',
        owner: 'Owner',
        employee: 'Team Member',
        accessActive: 'Active Access',
        settings: 'Settings',
        profile: 'Profile',
        security: 'Security',
        appearance: 'Appearance',
        manageProfileAndAccountSettings: 'Manage your profile and account settings',
        logOut: 'Log out',
    },
    es: {
        backoffice: 'Backoffice',
        operations: 'Operaciones',
        dashboard: 'Dashboard',
        businesses: 'Negocios',
        team: 'Equipo',
        sales: 'Ventas',
        purchases: 'Compras',
        expenses: 'Gastos',
        pointsOfSale: 'Puntos de venta',
        products: 'Productos',
        preparation: 'Preparación',
        access: 'Accesos',
        activeBusiness: 'Negocio activo',
        active: 'Activo',
        workspaceReady: 'Espacio de trabajo listo',
        currentRole: 'Rol actual',
        owner: 'Propietario',
        employee: 'Miembro del equipo',
        accessActive: 'Acceso activo',
        settings: 'Ajustes',
        profile: 'Perfil',
        security: 'Seguridad',
        appearance: 'Apariencia',
        manageProfileAndAccountSettings: 'Administra tu perfil y la configuración de tu cuenta',
        logOut: 'Cerrar sesión',
    },
} as const;

type SupportedLocale = keyof typeof messages;
type TranslationKey = keyof (typeof messages)['en'];

export function useTranslations() {
    const page = usePage<{ locale?: string }>();

    const locale = computed<SupportedLocale>(() => {
        const currentLocale = page.props.locale?.toLowerCase();

        if (currentLocale?.startsWith('es')) {
            return 'es';
        }

        return 'en';
    });

    const t = (key: TranslationKey): string => messages[locale.value][key];

    return {
        locale,
        t,
    };
}
