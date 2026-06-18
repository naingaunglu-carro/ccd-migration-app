<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Password from 'primevue/password';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(store.url(), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div
        class="flex min-h-screen items-center justify-center bg-surface-50 p-4 dark:bg-surface-950"
    >
        <Card class="w-full max-w-md shadow-lg">
            <template #title>
                <span class="text-xl font-semibold">Sign in</span>
            </template>
            <template #subtitle>
                Enter your email and password to continue
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

                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <label for="password" class="text-sm font-medium">
                                Password
                            </label>
                            <Link
                                v-if="canResetPassword"
                                :href="request().url"
                                class="text-sm text-primary hover:underline"
                            >
                                Forgot password?
                            </Link>
                        </div>
                        <Password
                            v-model="form.password"
                            input-id="password"
                            :feedback="false"
                            toggle-mask
                            autocomplete="current-password"
                            placeholder="Password"
                            fluid
                            :invalid="!!form.errors.password"
                        />
                        <small v-if="form.errors.password" class="text-red-500">
                            {{ form.errors.password }}
                        </small>
                    </div>

                    <div class="flex items-center gap-2">
                        <Checkbox
                            v-model="form.remember"
                            input-id="remember"
                            binary
                        />
                        <label for="remember" class="text-sm">Remember me</label>
                    </div>

                    <Button
                        type="submit"
                        label="Sign in"
                        icon="pi pi-sign-in"
                        :loading="form.processing"
                        fluid
                    />
                </form>
            </template>
        </Card>
    </div>
</template>
