import { defineRoute } from '@tolki/ts';

export const index = defineRoute({
    name: 'prism.index',
    url: '/prism',
    methods: ['get', 'head'] as const,
});

/** @see Workbench\App\Http\Controllers\Prism\PrismController */
const PrismController = {
    index,
};

export default PrismController;
