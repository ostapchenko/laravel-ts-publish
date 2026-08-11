import { type AsEnum } from '@tolki/ts';

import { InvoiceStatus } from '../../enums';
import type { User } from '../../../app/models';
import type { DueAtNoticeType } from '../../enums';
import type { Invoice, Payment } from '../../models';
import type { PaymentResource } from '.';

/**
 * Exercises: when(cond, EnumResource::make) — conditional enum, cross-module
 * whenLoaded bare (App\User), Resource::collection sibling, whenCounted,
 * when(cond, value), mergeWhen.
 *
 * @see Workbench\Accounting\Http\Resources\InvoiceResource
 */
export interface InvoiceResource
{
    id: number;
    number: string;
    status?: AsEnum<typeof InvoiceStatus>;
    subtotal: number;
    tax: number;
    total: number;
    due_at: string | null;
    issued_at?: string | null;
    paid_at?: string | null;
    user?: User;
    payments?: PaymentResource[];
    payments_count?: number;
    notes?: string | null;
    latest_payment_only: Pick<Payment, 'invoice_id' | 'status' | 'method' | 'currency' | 'amount' | 'reference' | 'paid_at'> | null;
    latest_payment_excluded: { id: number; created_at: string | null; updated_at: string | null; due_notice: DueAtNoticeType; invoice: Invoice } | null;
}
