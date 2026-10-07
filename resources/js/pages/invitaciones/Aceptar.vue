<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login, logout, register } from '@/routes';
import { notice } from '@/routes/verification';

/*
 * La pantalla que ve quien recibe una invitación a una ficha.
 *
 * ⚠️ No trae ni un dato clínico: cualquiera con el enlace llega hasta acá. Se
 * muestra el nombre del paciente y quién invita —lo justo para reconocer de qué
 * se trata— y la historia aparece recién después de aceptar con la cuenta
 * correcta. Con el enlace roto no se muestra ni eso: nada de lo que trae la URL
 * es confiable.
 *
 * Va con el layout de ingreso (ver `app.ts`): muchas veces se abre sin sesión.
 */
defineOptions({
    layout: {
        title: 'Te invitaron a una ficha',
    },
});

defineProps<{
    estado:
        | 'invalida'
        | 'vencida'
        | 'sin_sesion'
        | 'sin_verificar'
        | 'otra_cuenta'
        | 'ya_tiene_acceso'
        | 'listo';
    paciente: string | null;
    invitadoPor: string | null;
    email: string | null;
    puedeEditar: boolean;
    urlFirmada: string | null;
}>();
</script>

<template>
    <Head title="Invitación" />

    <div class="space-y-6">
        <!-- Enlace roto: nada de esta URL es confiable, así que no se muestra nada. -->
        <div v-if="estado === 'invalida'" class="space-y-3">
            <p class="rounded-md border p-3">
                Este enlace no es válido. Puede que se haya cortado al copiarlo:
                probá abrirlo de nuevo directamente desde el mail.
            </p>
            <Button as-child variant="secondary" class="w-full">
                <Link :href="login()">Ir a MiSalud</Link>
            </Button>
        </div>

        <!-- Vencido: los datos sí son auténticos, y se puede decir a quién pedirle otro. -->
        <div v-else-if="estado === 'vencida'" class="space-y-3">
            <p class="rounded-md border p-3">
                La invitación a la ficha de
                <strong>{{ paciente }}</strong> venció. Pedile a
                {{ invitadoPor }} que te mande otra.
            </p>
            <Button as-child variant="secondary" class="w-full">
                <Link :href="login()">Ir a MiSalud</Link>
            </Button>
        </div>

        <template v-else>
            <div class="space-y-1">
                <p class="text-lg font-semibold break-words">{{ paciente }}</p>
                <p class="text-sm text-muted-foreground">
                    Te invitó {{ invitadoPor }}
                </p>
            </div>

            <p v-if="puedeEditar" class="text-sm text-muted-foreground">
                Vas a poder ver su historia y
                <strong class="text-foreground"
                    >también cargar y corregir datos</strong
                >. La ficha sigue siendo de {{ invitadoPor }}.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                Vas a poder ver su historia completa, pero
                <strong class="text-foreground"
                    >no cargar ni modificar nada</strong
                >.
            </p>

            <!-- El POST va a la URL firmada que armó el servidor: Wayfinder no puede armar una. -->
            <Form
                v-if="estado === 'listo' || estado === 'ya_tiene_acceso'"
                method="post"
                :action="urlFirmada ?? ''"
                v-slot="{ processing }"
            >
                <Button type="submit" class="w-full" :disabled="processing">
                    <Spinner v-if="processing" />
                    {{
                        estado === 'listo'
                            ? `Aceptar y ver la ficha de ${paciente}`
                            : 'Ya tenés acceso: ver la ficha'
                    }}
                </Button>
            </Form>

            <!--
                Sin sesión. El aviso del mail va ANTES del botón a propósito:
                registrarse con otra dirección deja la invitación inservible, y
                enterarse después es peor.
            -->
            <div v-else-if="estado === 'sin_sesion'" class="space-y-3">
                <p class="rounded-md border p-3 text-sm">
                    Tenés que entrar con
                    <strong class="break-all">{{ email }}</strong
                    >, que es la dirección a la que {{ invitadoPor }} mandó la
                    invitación.
                </p>

                <Button as-child class="w-full">
                    <Link :href="login()">Ingresar</Link>
                </Button>

                <p class="text-center text-sm text-muted-foreground">
                    ¿Todavía no tenés cuenta?
                    <TextLink :href="register()">Creá una</TextLink>
                </p>
            </div>

            <div v-else-if="estado === 'sin_verificar'" class="space-y-3">
                <p class="rounded-md border p-3 text-sm">
                    Antes de ver la ficha tenés que confirmar tu dirección de
                    correo.
                </p>
                <Button as-child class="w-full">
                    <Link :href="notice()">Confirmar mi correo</Link>
                </Button>
                <p class="text-sm text-muted-foreground">
                    Cuando la confirmes, volvé a abrir el enlace del mail.
                </p>
            </div>

            <div v-else class="space-y-3">
                <p class="rounded-md border p-3 text-sm">
                    Esta invitación es para
                    <strong class="break-all">{{ email }}</strong
                    >, y entraste con otra cuenta.
                </p>
                <Form v-bind="logout.form()" v-slot="{ processing }">
                    <Button
                        type="submit"
                        variant="secondary"
                        class="w-full"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        Salir y entrar con la otra cuenta
                    </Button>
                </Form>
            </div>
        </template>
    </div>
</template>
