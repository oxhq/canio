package browser

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/chromedp/chromedp"
	"github.com/oxhq/canio/runtime/stagehand/internal/config"
)

type processFactory struct {
	config config.RuntimeConfig
}

type browserProcess struct {
	id           int
	allocatorCtx context.Context
	allocatorEnd context.CancelFunc
	browserCtx   context.Context
	browserEnd   context.CancelFunc
}

func (f processFactory) Start(ctx context.Context, id int) (BrowserProcess, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}

	opts, err := allocatorOptions(f.config, id)
	if err != nil {
		return nil, err
	}

	allocatorCtx, allocatorEnd := chromedp.NewExecAllocator(context.Background(), opts...)
	browserCtx, browserEnd := chromedp.NewContext(allocatorCtx)

	if err := chromedp.Run(browserCtx); err != nil {
		browserEnd()
		allocatorEnd()
		return nil, err
	}

	return &browserProcess{
		id:           id,
		allocatorCtx: allocatorCtx,
		allocatorEnd: allocatorEnd,
		browserCtx:   browserCtx,
		browserEnd:   browserEnd,
	}, nil
}

func (p *browserProcess) ID() int {
	if p == nil {
		return 0
	}

	return p.id
}

func (p *browserProcess) Context() context.Context {
	if p == nil {
		return context.Background()
	}

	return p.browserCtx
}

func (p *browserProcess) Close() {
	if p == nil {
		return
	}

	if p.browserEnd != nil {
		p.browserEnd()
	}

	if p.allocatorEnd != nil {
		p.allocatorEnd()
	}
}

func allocatorOptions(cfg config.RuntimeConfig, id int) ([]chromedp.ExecAllocatorOption, error) {
	opts := append([]chromedp.ExecAllocatorOption{}, chromedp.DefaultExecAllocatorOptions[:]...)

	if path := strings.TrimSpace(cfg.ChromiumPath); path != "" {
		opts = append(opts, chromedp.ExecPath(path))
	}

	if dir := strings.TrimSpace(cfg.UserDataDir); dir != "" {
		slotDir := filepath.Join(dir, "browser-"+sanitizeSlotID(id))
		if err := os.MkdirAll(slotDir, 0o755); err != nil {
			return nil, err
		}

		opts = append(opts, chromedp.UserDataDir(slotDir))
	}

	if !cfg.Headless {
		opts = append(opts, chromedp.Flag("headless", false))
	}

	if cfg.IgnoreHTTPSErrors {
		opts = append(opts, chromedp.IgnoreCertErrors)
	}

	opts = append(opts, chromedp.WindowSize(1440, 2000))

	return opts, nil
}

func sanitizeSlotID(id int) string {
	return strings.TrimSpace(fmt.Sprintf("%03d", id))
}
