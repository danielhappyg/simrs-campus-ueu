import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clock3, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    reason: string;
    workQueueUrl: string;
};

export default function WorkflowConflict({ reason, workQueueUrl }: Props) {
    return (
        <>
            <Head title="Tahap belum tersedia" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 items-center px-4 py-10 md:px-6 md:py-16">
                <section
                    aria-labelledby="workflow-conflict-title"
                    className="clinical-shadow w-full overflow-hidden rounded-lg border border-[#efb08d] bg-white"
                >
                    <div className="grid md:grid-cols-[13rem_minmax(0,1fr)]">
                        <div className="flex flex-col justify-between border-b border-[#efb08d] bg-[#fff4ed] p-6 md:border-r md:border-b-0">
                            <Clock3
                                className="size-10 text-[#b53b13]"
                                aria-hidden="true"
                            />
                            <div className="mt-8">
                                <p className="font-mono text-sm font-medium text-[#9a3412]">
                                    HTTP 409
                                </p>
                                <p className="mt-1 text-xs font-bold tracking-wider text-[#7c2d12] uppercase">
                                    Gate alur kerja
                                </p>
                            </div>
                        </div>

                        <div className="p-6 md:p-8">
                            <Badge className="border-[#b53b13] bg-[#f05828] text-white">
                                Tahap aman · tidak ada data diubah
                            </Badge>
                            <h1
                                id="workflow-conflict-title"
                                className="mt-5 text-3xl font-semibold text-[#063650]"
                            >
                                Tahap belum tersedia
                            </h1>
                            <p className="mt-3 text-base leading-7 text-[#365867]">
                                {reason}
                            </p>

                            <div className="mt-6 flex items-start gap-3 rounded-md border border-[#b9d5e3] bg-[#eaf4f8] p-4 text-sm leading-6 text-[#174c68]">
                                <ShieldCheck
                                    className="mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <p>
                                    Permintaan dikenali dan data sintetis tetap
                                    tersimpan. Selesaikan tahap sebelumnya atau
                                    tunggu keputusan supervisor sebelum mencoba
                                    kembali.
                                </p>
                            </div>

                            <div className="mt-7">
                                <Button asChild>
                                    <Link href={workQueueUrl}>
                                        <ArrowLeft
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Kembali ke antrean kerja
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
