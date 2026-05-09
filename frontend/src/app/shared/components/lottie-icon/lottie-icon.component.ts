import {
  Component,
  Input,
  ElementRef,
  OnInit,
  OnDestroy,
  OnChanges,
  SimpleChanges,
  ViewChild,
  ChangeDetectionStrategy,
} from '@angular/core';

@Component({
  selector: 'app-lottie-icon',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      #container
      [style.width.px]="size"
      [style.height.px]="size"
      [style.display]="'flex'"
      [style.align-items]="'center'"
      [style.justify-content]="'center'"
    ></div>
  `,
  styles: [
    `
      :host {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }
    `,
  ],
})
export class LottieIconComponent implements OnInit, OnChanges, OnDestroy {
  @Input() src!: string;
  @Input() size = 40;
  @Input() loop = true;
  @Input() speed = 1;

  @ViewChild('container', { static: true }) containerRef!: ElementRef<HTMLDivElement>;

  private animation: any = null;
  private destroyed = false;

  ngOnInit(): void {
    this.loadAnimation();
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['src'] && !changes['src'].firstChange) {
      this.destroyAnimation();
      this.loadAnimation();
    }
  }

  ngOnDestroy(): void {
    this.destroyed = true;
    this.destroyAnimation();
  }

  private loadAnimation(): void {
    if (!this.src) return;
    import('lottie-web').then((mod) => {
      if (this.destroyed) return;
      const lottie = (mod as any).default ?? mod;
      this.animation = lottie.loadAnimation({
        container: this.containerRef.nativeElement,
        renderer: 'svg',
        loop: this.loop,
        autoplay: true,
        path: this.src,
      });
      if (this.speed !== 1) {
        this.animation.setSpeed(this.speed);
      }
    });
  }

  private destroyAnimation(): void {
    if (this.animation) {
      this.animation.destroy();
      this.animation = null;
    }
  }
}
