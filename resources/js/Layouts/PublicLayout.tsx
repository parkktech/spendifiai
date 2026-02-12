import { PropsWithChildren } from 'react';
import { Head } from '@inertiajs/react';
import Navbar from '@/Components/Marketing/Navbar';
import Footer from '@/Components/Marketing/Footer';

interface PublicLayoutProps {
    title?: string;
}

export default function PublicLayout({ title, children }: PropsWithChildren<PublicLayoutProps>) {
    return (
        <>
            {title && <Head title={title} />}
            <div className="flex min-h-screen flex-col bg-white">
                <Navbar />
                <main className="flex-1">{children}</main>
                <Footer />
            </div>
        </>
    );
}
