import { cva } from 'class-variance-authority';

export const buttonVariants = cva(
    'focus-ring inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-primary text-white hover:bg-primary-hover active:bg-brand-800',
                success: 'bg-success-500 text-white hover:bg-success-600 active:bg-success-700',
                secondary: 'border border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-50',
                ghost: 'text-neutral-700 hover:bg-neutral-100',
                danger: 'bg-danger-text text-white hover:opacity-90',
                'danger-ghost': 'text-danger-text hover:bg-danger-bg',
            },
            size: {
                sm: 'h-8 px-3 text-sm',
                default: 'h-10 px-4',
                lg: 'h-12 px-5 text-base',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'default',
        },
    },
);
