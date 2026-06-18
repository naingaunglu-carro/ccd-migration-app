<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(email.url());
};
</script>

<template>
    <Head title="Forgot password" />

    <div
        class="flex min-h-screen items-center justify-center bg-surface-50 p-4 dark:bg-surface-950"
    >
        <Card class="w-full max-w-md shadow-lg">
            <template #title>
                <span class="text-xl font-semibold">Forgot password</span>
            </template>
            <template #subtitle>
                Enter your email and we'll send a reset link
            </template>

            <template #content>
                <Message
                    v-if="status"
                    severity="success"
                    :closable="false"
                    class="mb-4"
                >
                    {{ status }}
                </Message>

                <form class="mt-2 flex flex-col gap-5" @submit.prevent="submit">
                    <div class="flex flex-col gap-2">
                        <label for="email" class="text-sm font-medium">
                            Email address
                        </label>
                        <InputText
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            placeholder="email@example.com"
                            fluid
                            autofocus
                            :invalid="!!form.errors.email"
                        />
                        <small v-if="form.errors.email" class="text-red-500">
                            {{ form.errors.email }}
                        </small>
                    </div>

                    <Button
                        type="submit"
                        label="Email password reset link"
                        icon="pi pi-envelope"
                        :loading="form.processing"
                        fluid
                    />

                    <p class="text-center text-sm text-surface-500">
                        Or, return to
                        <Link
                            :href="login().url"
                            class="text-primary hover:underline"
                        >
                            log in
                        </Link>
                    </p>
                </form>
            </template>
        </Card>
    </div>
</template>
