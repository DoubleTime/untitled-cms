import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { MailCheck, MailX, ArrowLeft } from 'lucide-react';

interface Props {
    email?: string;
    error?: string;
}

export default function Unsubscribed({ email, error }: Props) {
    return (
        <div className="min-h-screen bg-[#0f172a] relative overflow-hidden flex flex-col justify-center py-12 sm:px-6 lg:px-8">
            <Head title="Unsubscribed" />
            
            {/* Background Aesthetic Orbs */}
            <div className="absolute top-[-10%] right-[-10%] w-[500px] h-[500px] bg-indigo-600/20 blur-[120px] rounded-full pointer-events-none" />
            <div className="absolute bottom-[-10%] left-[-10%] w-[400px] h-[400px] bg-purple-600/20 blur-[100px] rounded-full pointer-events-none" />

            <div className="relative z-10 sm:mx-auto sm:w-full sm:max-w-md px-4">
                <div className="bg-white/5 backdrop-blur-xl border border-white/10 py-10 px-6 shadow-2xl rounded-3xl sm:px-12 text-center transition-all animate-in fade-in zoom-in duration-500">
                    
                    {error ? (
                        <>
                            <div className="mb-6 flex justify-center">
                                <div className="rounded-2xl bg-red-500/10 p-4 border border-red-500/20">
                                    <MailX className="h-10 w-10 text-red-500" />
                                </div>
                            </div>
                            <h2 className="text-3xl font-extrabold text-white mb-4 tracking-tight">Something went wrong</h2>
                            <p className="text-slate-400 text-lg mb-10 leading-relaxed font-light">
                                {error}
                            </p>
                        </>
                    ) : (
                        <>
                            <div className="mb-6 flex justify-center">
                                <div className="rounded-2xl bg-emerald-500/10 p-4 border border-emerald-500/20 animate-pulse">
                                    <MailCheck className="h-10 w-10 text-emerald-500" />
                                </div>
                            </div>
                            <h2 className="text-3xl font-extrabold text-white mb-4 tracking-tight">Got it! You're unsubscribed</h2>
                            <p className="text-slate-400 text-lg mb-10 leading-relaxed font-light">
                                We've removed <span className="text-indigo-400 font-medium">{email}</span> from our system. 
                                You won't receive these types of communications from us anymore.
                            </p>
                        </>
                    )}

                    <Link
                        href="/"
                        className="group relative inline-flex items-center justify-center px-8 py-3.5 font-bold text-white transition-all duration-200 bg-indigo-600 font-pj rounded-xl focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-gray-900 overflow-hidden hover:bg-indigo-500"
                    >
                        <ArrowLeft className="mr-2 h-4 w-4 transition-transform group-hover:-translate-x-1" />
                        <span>Return to Site</span>
                    </Link>
                    
                    <p className="mt-8 text-slate-500 text-xs uppercase tracking-widest font-semibold">
                        Powered by CMS Engine
                    </p>
                </div>
            </div>
            
            {/* Mesh pattern overlay */}
            <div className="absolute inset-0 z-0 opacity-20 pointer-events-none bg-[url('/images/noise.svg')] mix-blend-overlay" />
        </div>
    );
}
