<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ReglasDeClave from '@/components/ReglasDeClave.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    /** Falso para las cuentas que entran con Google: nunca eligieron una. */
    tieneContrasena: boolean;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Configuración de seguridad',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Configuración de seguridad" />

    <h1 class="sr-only">Configuración de seguridad</h1>

    <!--
        En la demo la cuenta la comparten todos los visitantes: una clave nueva,
        un 2FA o una passkey dejarían afuera a los demás hasta el reset. El
        servidor ya lo rechaza (ProtegerDemo); acá solo no se ofrece.
    -->
    <div v-if="$page.props.demo" class="space-y-2">
        <Heading
            variant="small"
            title="Seguridad"
            description="En la demo no se puede cambiar la contraseña ni activar la verificación en dos pasos o las llaves de acceso: la cuenta es la misma para todos los visitantes."
        />
    </div>

    <div v-else class="space-y-6">
        <!--
            Quien entró con Google no tiene contraseña: se le ofrece DEFINIR una
            -para poder entrar también por email- en vez de cambiarla, y no se
            le pide la actual, porque no existe.
        -->
        <Heading
            variant="small"
            :title="
                tieneContrasena
                    ? 'Cambiar la contraseña'
                    : 'Definir una contraseña'
            "
            :description="
                tieneContrasena
                    ? 'Usá una contraseña larga y difícil de adivinar'
                    : 'Entrás con Google. Si querés, definí una contraseña para poder entrar también con tu email.'
            "
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div v-if="tieneContrasena" class="grid gap-2">
                <Label for="current_password">Contraseña actual</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Contraseña actual"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">{{
                    tieneContrasena ? 'Contraseña nueva' : 'Contraseña'
                }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    :placeholder="
                        tieneContrasena ? 'Contraseña nueva' : 'Contraseña'
                    "
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
                <ReglasDeClave :visible="!!errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Repetí la contraseña</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Repetí la contraseña"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    Guardar
                </Button>
            </div>
        </Form>
    </div>

    <ManageTwoFactor
        v-if="!$page.props.demo"
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />

    <ManagePasskeys
        v-if="!$page.props.demo"
        :canManagePasskeys="canManagePasskeys"
        :passkeys="passkeys"
    />
</template>
