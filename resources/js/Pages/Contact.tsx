import { useState, FormEvent } from 'react';
import { Link } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import HeroSection from '@/Components/Marketing/HeroSection';
import { Mail, MessageSquare, ArrowRight } from 'lucide-react';

export default function Contact() {
    const [submitted, setSubmitted] = useState(false);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setSubmitted(true);
    }

    return (
        <PublicLayout
            title="Contact Us - LedgerIQ Support"
            description="Get in touch with the LedgerIQ team. Email us at support@ledgeriq.com or use our contact form for questions about AI expense tracking, bank connections, or tax exports."
            breadcrumbs={[{ name: 'Contact', url: '/contact' }]}
        >
            <HeroSection
                title="Get In Touch"
                subtitle="Have a question, suggestion, or feedback? We'd love to hear from you."
            />

            <section className="px-6 py-20">
                <div className="mx-auto grid max-w-5xl grid-cols-1 gap-12 lg:grid-cols-3">
                    {/* Contact Form */}
                    <div className="lg:col-span-2">
                        {submitted ? (
                            <div className="rounded-2xl border border-sw-border bg-sw-success-light p-12 text-center">
                                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-sw-success text-white">
                                    <Mail className="h-8 w-8" />
                                </div>
                                <h2 className="text-xl font-bold text-sw-text">Message Sent!</h2>
                                <p className="mt-2 text-sw-muted">
                                    Thank you for reaching out. We&apos;ll get back to you within 24 hours.
                                </p>
                            </div>
                        ) : (
                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="name" className="block text-sm font-medium text-sw-text-secondary">
                                            Name
                                        </label>
                                        <input
                                            type="text"
                                            id="name"
                                            required
                                            className="mt-2 block w-full rounded-lg border-sw-border shadow-sm focus:border-sw-accent focus:ring-sw-accent"
                                        />
                                    </div>
                                    <div>
                                        <label htmlFor="email" className="block text-sm font-medium text-sw-text-secondary">
                                            Email
                                        </label>
                                        <input
                                            type="email"
                                            id="email"
                                            required
                                            className="mt-2 block w-full rounded-lg border-sw-border shadow-sm focus:border-sw-accent focus:ring-sw-accent"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label htmlFor="subject" className="block text-sm font-medium text-sw-text-secondary">
                                        Subject
                                    </label>
                                    <input
                                        type="text"
                                        id="subject"
                                        required
                                        className="mt-2 block w-full rounded-lg border-sw-border shadow-sm focus:border-sw-accent focus:ring-sw-accent"
                                    />
                                </div>
                                <div>
                                    <label htmlFor="message" className="block text-sm font-medium text-sw-text-secondary">
                                        Message
                                    </label>
                                    <textarea
                                        id="message"
                                        rows={6}
                                        required
                                        className="mt-2 block w-full rounded-lg border-sw-border shadow-sm focus:border-sw-accent focus:ring-sw-accent"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    className="inline-flex items-center gap-2 rounded-lg bg-sw-accent px-6 py-3 text-sm font-semibold text-white shadow-sm transition-all hover:bg-sw-accent-hover"
                                >
                                    Send Message
                                    <ArrowRight className="h-4 w-4" />
                                </button>
                            </form>
                        )}
                    </div>

                    {/* Sidebar */}
                    <div className="space-y-8">
                        <div className="rounded-2xl border border-sw-border bg-white p-6 shadow-sm">
                            <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-sw-accent-light text-sw-accent">
                                <Mail className="h-5 w-5" />
                            </div>
                            <h2 className="font-semibold text-sw-text">Email Us</h2>
                            <p className="mt-1 text-sm text-sw-muted">support@ledgeriq.com</p>
                        </div>
                        <div className="rounded-2xl border border-sw-border bg-white p-6 shadow-sm">
                            <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-sw-accent-light text-sw-accent">
                                <MessageSquare className="h-5 w-5" />
                            </div>
                            <h2 className="font-semibold text-sw-text">Check Our FAQ</h2>
                            <p className="mt-1 text-sm text-sw-muted">
                                Find quick answers to common questions.
                            </p>
                            <Link
                                href="/faq"
                                className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-sw-accent hover:underline"
                            >
                                Visit FAQ
                                <ArrowRight className="h-3.5 w-3.5" />
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
