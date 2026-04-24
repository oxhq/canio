package browser

import (
	"os"
	"path/filepath"
	"strconv"
	"testing"
)

func TestSlotUserDataDirIsNamespacedByStagehandProcess(t *testing.T) {
	base := filepath.Join("runtime", "chromium-profile")

	got := slotUserDataDir(base, 7)
	want := filepath.Join(base, "stagehand-"+strconv.Itoa(os.Getpid()), "browser-007")

	if got != want {
		t.Fatalf("slotUserDataDir() = %q, want %q", got, want)
	}
}
