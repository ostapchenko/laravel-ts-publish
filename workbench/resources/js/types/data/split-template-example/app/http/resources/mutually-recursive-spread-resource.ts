/**
 * Two methods that spread each other. Without a visited-method guard this recurses until the
 * parser exhausts memory; with one it degrades to an empty analysis.
 *
 * @see Workbench\App\Http\Resources\MutuallyRecursiveSpreadResource
 */
export interface MutuallyRecursiveSpreadResource
{
    name: string;
}
