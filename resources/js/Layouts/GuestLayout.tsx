import AuthLayout from '@/Layouts/AuthLayout';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return <AuthLayout>{children}</AuthLayout>;
}
