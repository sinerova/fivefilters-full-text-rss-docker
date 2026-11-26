variable "IMAGE" {
	default = "ghcr.io/sinerova/fivefilters-full-text-rss"
}

variable "VERSION" {
	default = "3.8.1-hardened"
}

group "default" {
	targets = ["all"]
}

target "all" {
  tags = ["${IMAGE}:latest", "${IMAGE}:${VERSION}"]
  # Published for linux/amd64 and linux/arm64. linux/arm/v7 is excluded.
  platforms = ["linux/amd64", "linux/arm64"]
}

target "amd64" {
	tags = ["${IMAGE}:${VERSION}"]
	platforms = ["linux/amd64"]
}
