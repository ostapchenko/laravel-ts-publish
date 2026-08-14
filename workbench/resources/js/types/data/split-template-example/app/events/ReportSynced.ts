import type { Report as MarketingReportReport } from '../models/marketing/report';
import type { Report as SalesReportReport } from '../models/sales/report';

/** @see Workbench\App\Events\ReportSynced */
export interface ReportSynced {
    salesReport: Partial<SalesReportReport>;
    marketingReport: Partial<MarketingReportReport>;
}
