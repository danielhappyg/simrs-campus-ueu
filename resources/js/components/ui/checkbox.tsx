import { CheckIcon } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"

type CheckboxProps = Omit<React.ComponentProps<"input">, "type"> & {
  onCheckedChange?: (checked: boolean) => void
}

function Checkbox({
  className,
  onChange,
  onCheckedChange,
  ...props
}: CheckboxProps) {
  return (
    <span className="relative inline-flex size-11 shrink-0 items-center justify-center md:size-4">
      <input
        type="checkbox"
        data-slot="checkbox"
        className={cn(
          "peer absolute inset-0 size-full cursor-pointer appearance-none rounded-md outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50",
          className
        )}
        onChange={(event) => {
          onChange?.(event)
          onCheckedChange?.(event.target.checked)
        }}
        {...props}
      />
      <span
        data-slot="checkbox-indicator"
        aria-hidden="true"
        className="pointer-events-none flex size-4 items-center justify-center rounded-[4px] border border-input bg-background text-primary-foreground shadow-xs transition-[border-color,background-color,box-shadow] peer-checked:border-primary peer-checked:bg-primary peer-focus-visible:border-ring peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50 peer-aria-invalid:border-destructive peer-aria-invalid:ring-[3px] peer-aria-invalid:ring-destructive/20 peer-disabled:opacity-50 peer-checked:[&>svg]:opacity-100"
      >
        <CheckIcon className="size-3.5 opacity-0" />
      </span>
    </span>
  )
}

export { Checkbox }
