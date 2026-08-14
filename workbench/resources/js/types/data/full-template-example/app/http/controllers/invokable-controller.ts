import { defineRoute } from '@tolki/ts';

export const invoke = defineRoute({
    url: '/invokable',
    methods: ['get', 'head'] as const,
});

/** @see Workbench\App\Http\Controllers\InvokableController */
const InvokableController = invoke;

export default InvokableController;
