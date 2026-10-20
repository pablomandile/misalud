<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import BotonGoogle from '@/components/BotonGoogle.vue';
import InputError from '@/components/InputError.vue';
import ReglasDeClave from '@/components/ReglasDeClave.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login, privacidad, terminos } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Crear una cuenta',
        description: 'Completá tus datos para crear la cuenta',
    },
});
</script>

<template>
    <Head title="Crear cuenta" />

    <BotonGoogle modo="registrarse" class="mb-6" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Nombre</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    v-focus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Nombre y apellido"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Contraseña</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Contraseña"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
                <ReglasDeClave :visible="!!errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Repetí la contraseña</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Repetí la contraseña"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <!--
                La aceptación. Casilla nativa (manda "on", que la regla
                `accepted` sí acepta) y los enlaces en otra pestaña: abrirlos acá
                perdería todo lo que ya se cargó en el formulario.
            -->
            <div class="grid gap-1">
                <label
                    class="flex min-h-11 cursor-pointer items-start gap-3 text-sm text-pretty"
                >
                    <!--
                        El Checkbox del kit y no uno nativo: trae el área de 44
                        px (el nativo medía 18) y manda "on", que `accepted`
                        acepta. Sin `required`: su input es oculto y la
                        validación del navegador no puede llevarle el foco;
                        lo exige el servidor, con el error al lado.
                    -->
                    <Checkbox id="terminos" name="terminos" class="mt-1" />
                    <span>
                        Leí y acepto los
                        <a
                            :href="terminos().url"
                            target="_blank"
                            class="underline underline-offset-4 hover:text-primary"
                            >términos y condiciones</a
                        >
                        y la
                        <a
                            :href="privacidad().url"
                            target="_blank"
                            class="underline underline-offset-4 hover:text-primary"
                            >política de privacidad</a
                        >.
                    </span>
                </label>
                <InputError :message="errors.terminos" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Crear cuenta
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            ¿Ya tenés cuenta?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="6"
                >Iniciar sesión</TextLink
            >
        </div>
    </Form>
</template>
