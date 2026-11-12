{
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = {
    nixpkgs,
    flake-utils,
    ...
  }:
    flake-utils.lib.eachDefaultSystem (
      system: let
        pkgs = import nixpkgs {inherit system;};
        portablePhp = pkgs.php83.buildEnv {
          extensions = ({enabled, all}: enabled ++ [all.imagick]);
        };
      in {
        packages = pkgs.lib.optionalAttrs pkgs.stdenv.hostPlatform.isLinux {
          ci-chromium-sandbox = pkgs.chromium.sandbox;
          ci-plan-tools = pkgs.buildEnv {
            name = "portable-cms-ci-plan";
            paths = [pkgs.git pkgs.nodejs_24];
          };
        };
        devShells.default = pkgs.mkShell {
          buildInputs = with pkgs; [
            biome
            apacheHttpd
            cacert
            imagemagick
            just
            git
            ripgrep
            gitleaks
            ffmpeg
            mariadb
            nodejs_24
            opentype-sanitizer
            woff2
            validator-nu
            phpstan
            php83Packages.php-cs-fixer
            php83Packages.composer
            portablePhp
          ] ++ pkgs.lib.optionals pkgs.stdenv.hostPlatform.isLinux [pkgs.chromium pkgs.google-lighthouse];
        };
      }
    );
}
