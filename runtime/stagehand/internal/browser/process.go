package browser

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/chromedp/cdproto/emulation"
	"github.com/chromedp/cdproto/network"
	"github.com/chromedp/cdproto/page"
	cdpruntime "github.com/chromedp/cdproto/runtime"
	"github.com/chromedp/cdproto/target"
	"github.com/chromedp/chromedp"
	"github.com/go-rod/rod"
	"github.com/go-rod/rod/lib/launcher"
	"github.com/go-rod/rod/lib/proto"
	"github.com/oxhq/canio/runtime/stagehand/internal/config"
)

const (
	rendererDriverLocalCDP  = "local-cdp"
	rendererDriverRemoteCDP = "remote-cdp"
	rendererDriverRodCDP    = "rod-cdp"
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
	rodBrowser   *rod.Browser
	rodPage      *rod.Page
}

func (f processFactory) Start(ctx context.Context, id int) (BrowserProcess, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}

	started, err := f.newAllocator(ctx, id)
	if err != nil {
		return nil, err
	}

	var (
		browserCtx context.Context
		browserEnd context.CancelFunc
	)

	if started.targetID != "" {
		browserCtx, browserEnd = chromedp.NewContext(started.allocatorCtx, chromedp.WithTargetID(target.ID(started.targetID)))
	} else {
		browserCtx, browserEnd = chromedp.NewContext(started.allocatorCtx)
	}

	if err := chromedp.Run(browserCtx,
		network.Enable(),
		page.Enable(),
		cdpruntime.Enable(),
		emulation.SetEmulatedMedia().WithMedia("print"),
		chromedp.Navigate("about:blank"),
	); err != nil {
		browserEnd()
		started.allocatorEnd()
		return nil, err
	}

	return &browserProcess{
		id:           id,
		allocatorCtx: started.allocatorCtx,
		allocatorEnd: started.allocatorEnd,
		browserCtx:   browserCtx,
		browserEnd:   browserEnd,
		rodBrowser:   started.rodBrowser,
		rodPage:      started.rodPage,
	}, nil
}

type allocatorStart struct {
	allocatorCtx context.Context
	allocatorEnd context.CancelFunc
	targetID     string
	rodBrowser   *rod.Browser
	rodPage      *rod.Page
}

func (f processFactory) newAllocator(ctx context.Context, id int) (*allocatorStart, error) {
	driver, err := normalizeRendererDriver(f.config)
	if err != nil {
		return nil, err
	}

	switch driver {
	case rendererDriverLocalCDP:
		opts, err := allocatorOptions(f.config, id)
		if err != nil {
			return nil, err
		}

		allocatorCtx, allocatorEnd := chromedp.NewExecAllocator(backgroundBoundContext(ctx), opts...)
		return &allocatorStart{allocatorCtx: allocatorCtx, allocatorEnd: allocatorEnd}, nil
	case rendererDriverRemoteCDP:
		endpoint := strings.TrimSpace(f.config.RemoteCDPEndpoint)
		allocatorCtx, allocatorEnd := chromedp.NewRemoteAllocator(backgroundBoundContext(ctx), endpoint)
		return &allocatorStart{allocatorCtx: allocatorCtx, allocatorEnd: allocatorEnd}, nil
	case rendererDriverRodCDP:
		return newRodAllocator(ctx, f.config, id)
	default:
		return nil, fmt.Errorf("unsupported renderer driver %q", driver)
	}
}

func normalizeRendererDriver(cfg config.RuntimeConfig) (string, error) {
	driver := strings.ToLower(strings.TrimSpace(cfg.RendererDriver))
	remoteEndpoint := strings.TrimSpace(cfg.RemoteCDPEndpoint)

	if driver == "" {
		if remoteEndpoint != "" {
			driver = rendererDriverRemoteCDP
		} else {
			driver = rendererDriverRodCDP
		}
	}

	switch driver {
	case rendererDriverLocalCDP:
		return driver, nil
	case rendererDriverRodCDP:
		return driver, nil
	case rendererDriverRemoteCDP:
		if remoteEndpoint == "" {
			return "", fmt.Errorf("remote CDP endpoint is required when renderer driver is %q", rendererDriverRemoteCDP)
		}

		return driver, nil
	default:
		return "", fmt.Errorf("unsupported renderer driver %q", driver)
	}
}

func backgroundBoundContext(parent context.Context) context.Context {
	ctx, cancel := context.WithCancel(context.Background())

	go func() {
		select {
		case <-parent.Done():
			cancel()
		case <-ctx.Done():
		}
	}()

	return ctx
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

func (p *browserProcess) RodPage() *rod.Page {
	if p == nil {
		return nil
	}

	return p.rodPage
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
		slotDir := slotUserDataDir(dir, id)
		if err := os.MkdirAll(slotDir, 0o755); err != nil {
			return nil, err
		}

		opts = append(opts, chromedp.UserDataDir(slotDir))
	}

	if !cfg.Headless {
		opts = append(opts, chromedp.Flag("headless", false))
	}

	if cfg.DisableSandbox {
		opts = append(opts,
			chromedp.Flag("no-sandbox", true),
			chromedp.Flag("disable-setuid-sandbox", true),
		)
	}

	if cfg.IgnoreHTTPSErrors {
		opts = append(opts, chromedp.IgnoreCertErrors)
	}

	opts = append(opts, chromedp.WindowSize(1440, 2000))

	return opts, nil
}

func newRodAllocator(parent context.Context, cfg config.RuntimeConfig, id int) (*allocatorStart, error) {
	launcherCtx, launcherEnd := context.WithCancel(backgroundBoundContext(parent))
	rodLauncher := launcher.New().
		Context(launcherCtx).
		HeadlessNew(cfg.Headless).
		NoSandbox(cfg.DisableSandbox).
		Leakless(false).
		RemoteDebuggingPort(0)

	if path := strings.TrimSpace(cfg.ChromiumPath); path != "" {
		rodLauncher = rodLauncher.Bin(path)
	}

	if dir := strings.TrimSpace(cfg.UserDataDir); dir == "" {
		// Let Rod allocate and clean up an isolated temporary profile.
	} else {
		slotDir := slotUserDataDir(dir, id)
		if err := os.MkdirAll(slotDir, 0o755); err != nil {
			launcherEnd()
			return nil, err
		}
		rodLauncher = rodLauncher.UserDataDir(slotDir)
	}

	rodLauncher = rodLauncher.Set("remote-allow-origins", "*")
	rodLauncher = rodLauncher.Set("disable-gpu")
	rodLauncher = rodLauncher.Set("disable-extensions")
	rodLauncher = rodLauncher.Set("disable-sync")
	rodLauncher = rodLauncher.Set("window-size", "1440,2000")

	controlURL, err := rodLauncher.Launch()
	if err != nil {
		launcherEnd()
		return nil, err
	}

	// Wait for the browser process to be fully ready via its HTTP endpoint
	httpURL := strings.Replace(controlURL, "ws://", "http://", 1)
	if idx := strings.Index(httpURL, "/devtools/browser/"); idx >= 0 {
		httpURL = httpURL[:idx]
	}

	client := &http.Client{Timeout: 200 * time.Millisecond}
	ready := false
	for i := 0; i < 30; i++ {
		resp, err := client.Get(httpURL + "/json/version")
		if err == nil {
			resp.Body.Close()
			ready = true
			break
		}
		time.Sleep(100 * time.Millisecond)
	}

	if !ready {
		rodLauncher.Kill()
		rodLauncher.Cleanup()
		launcherEnd()
		return nil, fmt.Errorf("browser at %s failed to become ready via HTTP polling", controlURL)
	}

	rodBrowser := rod.New().ControlURL(controlURL)
	if err := rodBrowser.Connect(); err != nil {
		rodLauncher.Kill()
		rodLauncher.Cleanup()
		launcherEnd()
		return nil, fmt.Errorf("rod failed to connect for priming at %s: %w", controlURL, err)
	}

	rodPage, err := rodBrowser.Page(proto.TargetCreateTarget{URL: "about:blank"})
	if err != nil {
		_ = rodBrowser.Close()
		rodLauncher.Kill()
		rodLauncher.Cleanup()
		launcherEnd()
		return nil, fmt.Errorf("failed to create initial tab with rod: %w", err)
	}

	allocatorCtx, allocatorEnd := chromedp.NewRemoteAllocator(backgroundBoundContext(parent), controlURL)
	cleanup := func() {
		allocatorEnd()
		_ = rodBrowser.Close()
		rodLauncher.Kill()
		rodLauncher.Cleanup()
		launcherEnd()
	}

	return &allocatorStart{
		allocatorCtx: allocatorCtx,
		allocatorEnd: cleanup,
		targetID:     string(rodPage.TargetID),
		rodBrowser:   rodBrowser,
		rodPage:      rodPage,
	}, nil
}

func slotUserDataDir(base string, id int) string {
	return filepath.Join(strings.TrimSpace(base), processProfileNamespace(), "browser-"+sanitizeSlotID(id))
}

func processProfileNamespace() string {
	return fmt.Sprintf("stagehand-%d", os.Getpid())
}

func sanitizeSlotID(id int) string {
	return strings.TrimSpace(fmt.Sprintf("%03d", id))
}
