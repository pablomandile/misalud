<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { olvidarTodasLasCredenciales } from '@/lib/cacheCredencial';
import { logout, privacidad, terminos } from '@/routes';
import { edit as perfil } from '@/routes/profile';
import { datos as misDatos } from '@/routes/exportacion';
import { store } from '@/routes/terminos/aceptar';

/*
 * «Aceptar y continuar». La muestra el middleware `AceptoTerminos` a quien no
 * aceptó la versión vigente: las cuentas de antes de que existieran los
 * textos, las altas con Google y todos cuando se sube la versión.
 *
 * Sin aceptar igual se puede salir, llevarse los datos o borrar la cuenta: son
 * derechos que no dependen de aceptar nada (ver EXENTAS en el middleware).
 */

defineProps<{
    primeraVez: boolean;
    version: string;
}>();

defineOptions({
    layout: {
        title: 'Antes de seguir',
        description: 'Leé y aceptá los términos y la política de privacidad',
    },
});
</script>

<template>
    <Head title="Términos y privacidad" />

    <Form
        v-bind="store.form()"
        class="flex flex-col gap-6"
        #="{ errors, processing }"
    >
        <p class="text-sm text-pretty">
            <template v-if="primeraVez"> MiSalud ahora tiene </template>
            <template v-else>Actualizamos</template>
            los
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
            >
            (versión del {{ version }}). Explican qué datos guardamos, para qué,
            y qué podés hacer con ellos. Leelos antes de seguir.
        </p>

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
                <span
                    >Leí y acepto los términos y la política de
                    privacidad.</span
                >
            </label>
            <InputError :message="errors.terminos" />
        </div>

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Aceptar y continuar
        </Button>

        <p class="text-center text-sm text-pretty text-muted-foreground">
            Si no estás de acuerdo podés
            <a
                :href="misDatos().url"
                download
                class="underline underline-offset-4 hover:text-foreground"
                >descargar tus datos</a
            >,
            <Link
                :href="perfil()"
                class="underline underline-offset-4 hover:text-foreground"
                >eliminar tu cuenta</Link
            >
            o
            <Link
                :href="logout()"
                as="button"
                class="underline underline-offset-4 hover:text-foreground"
                @click="olvidarTodasLasCredenciales"
                >salir</Link
            >.
        </p>
    </Form>
</template>
