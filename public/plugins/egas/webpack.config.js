const path = require("path");
const webpack = require("webpack");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const CopyPlugin = require("copy-webpack-plugin");

module.exports = (env, argv) => {
  const isDevelopment = argv.mode === "development";

  return {
    entry: {
      admin: "./assets/js/admin.ts",
      frontend: "./assets/js/frontend.ts",
    },
    plugins: [
      new webpack.ProvidePlugin({
        $: "jquery",
        jQuery: "jquery",
      }),
      new MiniCssExtractPlugin(),
      new webpack.DefinePlugin({
        SOCKET_PORT: JSON.stringify(isDevelopment ? 4433 : undefined),
      }),
      // ✅ Ajout du plugin pour copier toutes les images
      new CopyPlugin({
        patterns: [
          {
            from: path.resolve(__dirname, "assets/images"),
            to: path.resolve(__dirname, "dist/images"),
            noErrorOnMissing: true, // Ne pas planter si le dossier n'existe pas
          },
        ],
      }),
    ],
    module: {
      rules: [
        {
          test: /\.tsx?$/,
          use: "ts-loader",
          exclude: /node_modules/,
        },
        {
          test: /\.(sa|sc|c)ss$/,
          use: [
            MiniCssExtractPlugin.loader,
            "css-loader",
            "postcss-loader",
            "sass-loader",
          ],
        },
        // Cette règle reste utile si tu veux importer des images dans le JS
        {
          test: /\.(png|jpe?g|gif|svg|webp|ico)$/i,
          type: 'asset/resource',
          generator: {
            filename: 'images/[name][ext]'
          }
        },
      ],
    },
    resolve: {
      extensions: [".tsx", ".ts", ".js", ".css", ".scss"],
    },
    output: {
      path: path.resolve(__dirname, "dist"),
      filename: "[name].js",
      clean: true,
    },
  };
};
