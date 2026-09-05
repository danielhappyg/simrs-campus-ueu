import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, PointerEvent as ReactPointerEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Clause = { title: string; body: string };

type Props = {
    encounter: {
        public_id: string;
        clinic_name: string;
        visit_date: string | null;
        patient: {
            full_name: string | null;
            medical_record_number: string | null;
            address_line: string | null;
            phone: string | null;
            responsible_party_name: string | null;
        };
    };
    form: {
        title: string;
        subtitle: string;
        hospital_name: string;
        hospital_address: string;
        intro: string;
        clauses: Clause[];
    };
    signatures: {
        explainer_name: string | null;
        patient_or_guardian_name: string | null;
        explainer_signature_png: string;
        patient_signature_png: string;
        signed_at: string | null;
    } | null;
    printUrl: string;
    backUrl: string;
};

function SignaturePad({
    label,
    initial,
    onChange,
}: {
    label: string;
    initial: string | null;
    onChange: (dataUrl: string) => void;
}) {
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const drawing = useRef(false);

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        const ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.strokeStyle = '#0f172a';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';

        if (initial) {
            const image = new Image();
            image.onload = () => {
                ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
            };
            image.src = initial;
        }
    }, [initial]);

    const point = (event: ReactPointerEvent<HTMLCanvasElement>) => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return { x: 0, y: 0 };
        }

        const rect = canvas.getBoundingClientRect();

        return {
            x: ((event.clientX - rect.left) / rect.width) * canvas.width,
            y: ((event.clientY - rect.top) / rect.height) * canvas.height,
        };
    };

    const emit = () => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        onChange(canvas.toDataURL('image/png'));
    };

    return (
        <div className="space-y-2">
            <div className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                {label}
            </div>
            <canvas
                ref={canvasRef}
                width={360}
                height={120}
                className="h-28 w-full touch-none rounded-md border border-[#e2e8f0] bg-white"
                onPointerDown={(event) => {
                    const canvas = canvasRef.current;
                    const ctx = canvas?.getContext('2d');

                    if (!canvas || !ctx) {
                        return;
                    }

                    drawing.current = true;
                    canvas.setPointerCapture(event.pointerId);
                    const { x, y } = point(event);
                    ctx.beginPath();
                    ctx.moveTo(x, y);
                }}
                onPointerMove={(event) => {
                    if (!drawing.current) {
                        return;
                    }

                    const ctx = canvasRef.current?.getContext('2d');

                    if (!ctx) {
                        return;
                    }

                    const { x, y } = point(event);
                    ctx.lineTo(x, y);
                    ctx.stroke();
                }}
                onPointerUp={() => {
                    drawing.current = false;
                    emit();
                }}
            />
            <Button
                type="button"
                variant="outline"
                className="w-full"
                onClick={() => {
                    const canvas = canvasRef.current;
                    const ctx = canvas?.getContext('2d');

                    if (!canvas || !ctx) {
                        return;
                    }

                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    onChange('');
                }}
            >
                Clear signature
            </Button>
        </div>
    );
}

export default function GeneralConsentPage({
    encounter,
    form,
    signatures,
    printUrl,
    backUrl,
}: Props) {
    const patientName = encounter.patient.full_name ?? '';
    const guardianName =
        encounter.patient.responsible_party_name || patientName;

    const consentForm = useForm({
        explainer_name: signatures?.explainer_name ?? '',
        patient_or_guardian_name:
            signatures?.patient_or_guardian_name ?? guardianName,
        explainer_signature_png: signatures?.explainer_signature_png ?? '',
        patient_signature_png: signatures?.patient_signature_png ?? '',
    });

    const [explainerPad, setExplainerPad] = useState(
        signatures?.explainer_signature_png ?? '',
    );
    const [patientPad, setPatientPad] = useState(
        signatures?.patient_signature_png ?? '',
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        consentForm.setData({
            ...consentForm.data,
            explainer_signature_png: explainerPad,
            patient_signature_png: patientPad,
        });
        consentForm.post(
            `/pendaftaran/kunjungan/${encounter.public_id}/consent`,
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <>
            <Head title={`${form.title} · ${patientName}`} />
            <div className="min-h-screen bg-[#f1f5f9] px-4 py-6 text-[#0f172a]">
                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Link
                            href={backUrl}
                            className="text-sm font-medium text-[#1b75bc] hover:underline"
                        >
                            Back to registration
                        </Link>
                        <a
                            href={printUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="text-sm font-medium text-[#1b75bc] hover:underline"
                        >
                            Print general consent
                        </a>
                    </div>

                    <article className="rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm">
                        <div className="text-sm font-bold text-[#123b63]">
                            {form.hospital_name}
                        </div>
                        <div className="text-xs text-[#64748b]">
                            {form.hospital_address}
                        </div>
                        <h1 className="mt-3 text-2xl font-bold text-[#123b63]">
                            {form.title}
                        </h1>
                        <p className="text-sm text-[#64748b]">
                            {form.subtitle}
                        </p>

                        <dl className="mt-4 grid grid-cols-[9rem_1fr] gap-x-3 gap-y-2 text-sm">
                            <dt className="text-[#64748b]">Name</dt>
                            <dd className="font-semibold">{guardianName}</dd>
                            <dt className="text-[#64748b]">Address</dt>
                            <dd className="font-semibold">
                                {encounter.patient.address_line || '—'}
                            </dd>
                            <dt className="text-[#64748b]">No. Telp</dt>
                            <dd className="font-semibold">
                                {encounter.patient.phone || '—'}
                            </dd>
                            <dt className="text-[#64748b]">No. RM</dt>
                            <dd className="font-semibold">
                                {encounter.patient.medical_record_number}
                            </dd>
                            <dt className="text-[#64748b]">Patient</dt>
                            <dd className="font-semibold">{patientName}</dd>
                            <dt className="text-[#64748b]">Date</dt>
                            <dd className="font-semibold">
                                {encounter.visit_date || '—'}
                            </dd>
                            <dt className="text-[#64748b]">Clinic / unit</dt>
                            <dd className="font-semibold">
                                {encounter.clinic_name}
                            </dd>
                        </dl>

                        <p className="mt-4 text-sm leading-relaxed">
                            Selaku pasien/wali hukum {form.hospital_name},
                            dengan ini menyatakan persetujuan:
                        </p>
                        <p className="mt-2 text-sm leading-relaxed">
                            {form.intro}
                        </p>

                        {form.clauses.map((clause) => (
                            <section key={clause.title} className="mt-4">
                                <h2 className="text-sm font-bold text-[#123b63]">
                                    {clause.title}
                                </h2>
                                <p className="mt-1 text-sm leading-relaxed text-[#0f172a]">
                                    {clause.body}
                                </p>
                            </section>
                        ))}

                        <form
                            onSubmit={submit}
                            className="mt-8 grid gap-6 border-t border-[#e2e8f0] pt-6 md:grid-cols-2"
                        >
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="explainer_name">
                                        Explainer name
                                    </Label>
                                    <Input
                                        id="explainer_name"
                                        value={consentForm.data.explainer_name}
                                        onChange={(event) =>
                                            consentForm.setData(
                                                'explainer_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <SignaturePad
                                    label="Explainer signature"
                                    initial={
                                        signatures?.explainer_signature_png ??
                                        null
                                    }
                                    onChange={setExplainerPad}
                                />
                            </div>
                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="patient_or_guardian_name">
                                        Patient / responsible person name
                                    </Label>
                                    <Input
                                        id="patient_or_guardian_name"
                                        value={
                                            consentForm.data
                                                .patient_or_guardian_name
                                        }
                                        onChange={(event) =>
                                            consentForm.setData(
                                                'patient_or_guardian_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <SignaturePad
                                    label="Patient / responsible person signature"
                                    initial={
                                        signatures?.patient_signature_png ??
                                        null
                                    }
                                    onChange={setPatientPad}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        consentForm.processing ||
                                        explainerPad === '' ||
                                        patientPad === ''
                                    }
                                    className="w-full bg-[#1b75bc] hover:bg-[#1665a3]"
                                >
                                    Save signatures
                                </Button>
                            </div>
                        </form>
                    </article>
                </div>
            </div>
        </>
    );
}
