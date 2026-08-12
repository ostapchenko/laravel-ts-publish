import type { PageMetaType } from '@js/types/page-meta';
import type { MenuSettingsType } from '@js/types/settings';
import type { WidgetConfigType } from '@js/types/widget-config';

/**
 * Regression fixture: a #[TsType(import: …)] token must reach the emitted file together with its
 * import from every result collector, not only analyzeReturnArray(). Each shape below reaches a
 * different collector and used to emit its token with no import at all (TS2304); each uses a
 * distinct #[TsType] class so no shape can ride on another's import.
 *
 * @see Workbench\App\Http\Resources\CustomImportChannelResource
 */
export interface CustomImportChannelResource
{
    id: number;
    inline_meta: { cfg: MenuSettingsType };
    merged_meta: PageMetaType;
    assigned_label: string;
    assigned_meta: WidgetConfigType;
}
