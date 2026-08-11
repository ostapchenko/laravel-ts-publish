import type { Report as MarketingReportReport } from './marketing/report';
import type { Report as SalesReportReport } from './sales/report';

/** @see Workbench\App\Models\Kpi */
export interface Kpi
{
    id: number;
    reportable_type: string;
    reportable_id: number;
    value: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface KpiRelations
{
    // Relations
    reportable: MarketingReportReport | SalesReportReport;
    // Counts
    reportable_count: number;
    // Exists
    reportable_exists: boolean;
}

export interface KpiAll extends Kpi, KpiRelations {}
