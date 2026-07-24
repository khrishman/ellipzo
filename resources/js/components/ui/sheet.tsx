import * as SheetPrimitive from '@radix-ui/react-dialog';
import { cva, type VariantProps } from 'class-variance-authority';
import { X } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

const Sheet = SheetPrimitive.Root;
const SheetTrigger = SheetPrimitive.Trigger;
const SheetClose = SheetPrimitive.Close;
const SheetPortal = SheetPrimitive.Portal;

const SheetOverlay = React.forwardRef<React.ElementRef<typeof SheetPrimitive.Overlay>, React.ComponentPropsWithoutRef<typeof SheetPrimitive.Overlay>>(
    ({ className, ...props }, ref) => (
        <SheetPrimitive.Overlay
            ref={ref}
            className={cn('fixed inset-0 z-50 bg-neutral-900/40 opacity-0 transition-opacity duration-150 data-[state=open]:opacity-100', className)}
            {...props}
        />
    ),
);
SheetOverlay.displayName = SheetPrimitive.Overlay.displayName;

const sheetVariants = cva('fixed z-50 flex flex-col gap-4 bg-white shadow-modal transition-transform duration-200 ease-in-out', {
    variants: {
        side: {
            top: 'inset-x-0 top-0 -translate-y-full border-b border-neutral-200 data-[state=open]:translate-y-0',
            bottom: 'inset-x-0 bottom-0 translate-y-full border-t border-neutral-200 data-[state=open]:translate-y-0',
            left: 'inset-y-0 left-0 h-full w-3/4 -translate-x-full border-r border-neutral-200 data-[state=open]:translate-x-0 sm:max-w-sm',
            right: 'inset-y-0 right-0 h-full w-3/4 translate-x-full border-l border-neutral-200 data-[state=open]:translate-x-0 sm:max-w-sm',
        },
    },
    defaultVariants: {
        side: 'right',
    },
});

interface SheetContentProps extends React.ComponentPropsWithoutRef<typeof SheetPrimitive.Content>, VariantProps<typeof sheetVariants> {}

const SheetContent = React.forwardRef<React.ElementRef<typeof SheetPrimitive.Content>, SheetContentProps>(
    ({ side = 'right', className, children, ...props }, ref) => (
        <SheetPortal>
            <SheetOverlay />
            <SheetPrimitive.Content ref={ref} className={cn(sheetVariants({ side }), className)} {...props}>
                {children}
                <SheetPrimitive.Close className="focus-ring absolute top-4 right-4 rounded-sm p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
                    <X className="size-4" aria-hidden="true" />
                    <span className="sr-only">Close</span>
                </SheetPrimitive.Close>
            </SheetPrimitive.Content>
        </SheetPortal>
    ),
);
SheetContent.displayName = SheetPrimitive.Content.displayName;

function SheetHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex flex-col gap-1.5 p-4', className)} {...props} />;
}

const SheetTitle = React.forwardRef<React.ElementRef<typeof SheetPrimitive.Title>, React.ComponentPropsWithoutRef<typeof SheetPrimitive.Title>>(
    ({ className, ...props }, ref) => <SheetPrimitive.Title ref={ref} className={cn('text-h4 text-neutral-900', className)} {...props} />,
);
SheetTitle.displayName = SheetPrimitive.Title.displayName;

const SheetDescription = React.forwardRef<
    React.ElementRef<typeof SheetPrimitive.Description>,
    React.ComponentPropsWithoutRef<typeof SheetPrimitive.Description>
>(({ className, ...props }, ref) => <SheetPrimitive.Description ref={ref} className={cn('text-body-sm text-neutral-500', className)} {...props} />);
SheetDescription.displayName = SheetPrimitive.Description.displayName;

export { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger };
