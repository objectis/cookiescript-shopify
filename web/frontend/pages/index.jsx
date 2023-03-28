import React from 'react'
import {
  Page,
  Layout,
} from "@shopify/polaris";
import { TitleBar } from "@shopify/app-bridge-react";

import { Application } from "../components/Application";

export default function HomePage() {
  return (
    <Page narrowWidth>
      <TitleBar title="Cookie Script" primaryAction={null} />
      <Layout>
        <Layout.Section>
          <Application />
        </Layout.Section>
      </Layout>
    </Page>
  );
}
