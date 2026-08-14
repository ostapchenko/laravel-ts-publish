import { defineRoute } from '@tolki/ts';

export const index = defineRoute({
    name: 'middleware.index',
    url: '/middleware',
    methods: ['get', 'head'] as const,
});

/** @see Workbench\App\Http\Controllers\MiddlewareController */
const MiddlewareController = {
    index,
};

export default MiddlewareController;
